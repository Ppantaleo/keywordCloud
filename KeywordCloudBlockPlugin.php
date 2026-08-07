<?php

/**
 * @file plugins/blocks/keywordCloud/KeywordCloudBlockPlugin.inc.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class KeywordCloudBlockPlugin
 *
 * @brief Class for KeywordCloud block plugin
 */

namespace APP\plugins\blocks\keywordCloud;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\submission\Submission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\controlledVocab\ControlledVocab;
use PKP\core\JSONMessage;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\BlockPlugin;

class KeywordCloudBlockPlugin extends BlockPlugin
{
    private const KEYWORD_BLOCK_MAX_ITEMS = 50;
    private const KEYWORD_BLOCK_CACHE_DAYS = 2;
    private const ONE_DAY_SECONDS = 60 * 60 * 24;
    private const TWO_DAYS_SECONDS = self::ONE_DAY_SECONDS * self::KEYWORD_BLOCK_CACHE_DAYS;

    public function getDisplayName(): string
    {
        return __('plugins.block.keywordCloud.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.block.keywordCloud.description');
    }

    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function cacheDismiss()
    {
        return null;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $url = $router->url(
            $request,
            null,
            null,
            'manage',
            null,
            ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'blocks']
        );
        array_unshift(
            $actions,
            new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings'))
        );

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $form = new SettingsForm($this, $request->getContext()->getId());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->execute();
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId());
        return new JSONMessage(true);
    }

    public function getContents($templateMgr, $request = null)
    {
        $context = $request->getContext();
        if (!$context) {
            return '';
        }

        $locale = Locale::getLocale();
        $primaryLocale = Locale::getPrimaryLocale();

        $yearStart = (int) $this->getSetting($context->getId(), 'yearStart');
        $yearEnd = (int) $this->getSetting($context->getId(), 'yearEnd');

        $keywords = $this->getCachedKeywords($context, $locale, $yearStart, $yearEnd);
        if ($keywords == '[]') {
            $keywords = $this->getCachedKeywords($context, $primaryLocale, $yearStart, $yearEnd);
        }

        $templateMgr->addJavaScript('d3', 'https://d3js.org/d3.v4.js');
        $templateMgr->addJavaScript(
            'd3-cloud',
            'https://cdn.jsdelivr.net/gh/holtzy/D3-graph-gallery@master/LIB/d3.layout.cloud.js'
        );

        $templateMgr->assign([
            'keywords' => $keywords,
            'keywordCloudPeriod' => $this->getSetting($context->getId(), 'showPeriod')
                ? $this->getPeriodLabel($yearStart, $yearEnd)
                : null,
        ]);

        return parent::getContents($templateMgr, $request);
    }

    /**
     * Human-readable label for the configured period, or null when no bound is
     * set (the cloud then covers the whole journal and there is nothing to say).
     */
    public function getPeriodLabel(int $yearStart, int $yearEnd): ?string
    {
        if ($yearStart && $yearEnd) {
            return $yearStart === $yearEnd
                ? (string) $yearStart
                : __('plugins.block.keywordCloud.period.range', ['yearStart' => $yearStart, 'yearEnd' => $yearEnd]);
        }
        if ($yearStart) {
            return __('plugins.block.keywordCloud.period.from', ['yearStart' => $yearStart]);
        }
        if ($yearEnd) {
            return __('plugins.block.keywordCloud.period.until', ['yearEnd' => $yearEnd]);
        }

        return null;
    }

    /**
     * Years in which the journal actually published something, newest first, so
     * the settings form can offer only the years that can yield keywords.
     *
     * @return int[]
     */
    public function getPublishedYears(int $contextId): array
    {
        $bounds = DB::table('publications as p')
            ->join('submissions as s', 'p.submission_id', '=', 's.submission_id')
            ->where('s.context_id', $contextId)
            ->where('p.status', Submission::STATUS_PUBLISHED)
            ->whereNotNull('p.date_published')
            // MIN/MAX on the date itself rather than on YEAR(): the latter does
            // not exist in PostgreSQL, which OJS also supports.
            ->selectRaw('MIN(p.date_published) AS first_date, MAX(p.date_published) AS last_date')
            ->first();

        if (!$bounds || !$bounds->first_date) {
            return [];
        }

        $firstYear = (int) substr($bounds->first_date, 0, 4);
        $lastYear = (int) substr($bounds->last_date, 0, 4);

        return array_reverse(range($firstYear, $lastYear));
    }

    private function getCachedKeywords(Context $context, string $locale, int $yearStart = 0, int $yearEnd = 0): ?string
    {
        // The period is part of the key: changing it in the settings must show
        // the new cloud immediately instead of waiting for the cache to expire.
        $cacheKey = 'keywordCloud_' . $context->getId() . '_' . $locale . '_' . $yearStart . '_' . $yearEnd;
        $expiration = \DateInterval::createFromDateString(self::KEYWORD_BLOCK_CACHE_DAYS . ' days');

        return Cache::remember($cacheKey, $expiration, function () use ($context, $locale, $yearStart, $yearEnd) {
            return $this->getJournalKeywords($context->getId(), $locale, $yearStart, $yearEnd);
        });
    }

    private function getJournalKeywords(int $journalId, string $locale, int $yearStart = 0, int $yearEnd = 0): string
    {
        $queryBuilder = Repo::publication()
            ->getCollector()
            ->filterByContextIds([$journalId])
            ->getQueryBuilder()
            ->whereIn('p.status', [Submission::STATUS_PUBLISHED]);

        // Compared as dates rather than with YEAR() to stay portable across the
        // databases OJS supports. A publication with no date_published cannot be
        // placed in a period, so it drops out as soon as either bound is set.
        if ($yearStart) {
            $queryBuilder->where('p.date_published', '>=', $yearStart . '-01-01');
        }
        if ($yearEnd) {
            $queryBuilder->where('p.date_published', '<=', $yearEnd . '-12-31');
        }

        $publicationIds = $queryBuilder
            ->select('p.publication_id')
            ->pluck('p.publication_id');

        $keywordNames = [];
        foreach ($publicationIds as $publicationId) {
            $publicationKeywords = Repo::controlledVocab()->getBySymbolic(
                ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
                Application::ASSOC_TYPE_PUBLICATION,
                $publicationId,
                [$locale]
            );
            $names = array_map('strtolower', array_column($publicationKeywords[$locale] ?? [], 'name'));
            // Deduplicate per publication rather than across the whole journal: a
            // word's weight is the number of articles that use it, so a keyword
            // repeated inside a single publication must still count once.
            $keywordNames = array_merge($keywordNames, array_unique($names));
        }

        $countKeywords = array_count_values($keywordNames);
        arsort($countKeywords, SORT_NUMERIC);

        // preserve_keys matters here: a purely numeric keyword ("2020") would
        // otherwise have its key reindexed by array_slice and reach the template
        // as "0", "1", ... instead of the word itself.
        $topKeywords = array_slice($countKeywords, 0, self::KEYWORD_BLOCK_MAX_ITEMS, true);
        $keywords = [];

        foreach ($topKeywords as $key => $countKey) {
            $keywords[] = (object) ['text' => $key, 'size' => $countKey];
        }

        return json_encode($keywords);
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\blocks\keywordCloud\KeywordCloudBlockPlugin', '\KeywordCloudBlockPlugin');
}

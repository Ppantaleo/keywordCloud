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
use APP\submission\Submission;
use Illuminate\Support\Facades\Cache;
use PKP\context\Context;
use PKP\controlledVocab\ControlledVocab;
use PKP\facades\Locale;
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

    public function getContents($templateMgr, $request = null)
    {
        $context = $request->getContext();
        if (!$context) {
            return '';
        }

        $locale = Locale::getLocale();
        $primaryLocale = Locale::getPrimaryLocale();

        $keywords = $this->getCachedKeywords($context, $locale);
        if ($keywords == '[]') {
            $keywords = $this->getCachedKeywords($context, $primaryLocale);
        }

        $templateMgr->addJavaScript('d3', 'https://d3js.org/d3.v4.js');
        $templateMgr->addJavaScript(
            'd3-cloud',
            'https://cdn.jsdelivr.net/gh/holtzy/D3-graph-gallery@master/LIB/d3.layout.cloud.js'
        );

        $templateMgr->assign('keywords', $keywords);
        return parent::getContents($templateMgr, $request);
    }

    private function getCachedKeywords(Context $context, string $locale): ?string
    {
        $cacheKey = 'keywordCloud_' . $context->getId() . '_' . $locale;
        $expiration = \DateInterval::createFromDateString(self::KEYWORD_BLOCK_CACHE_DAYS . ' days');

        return Cache::remember($cacheKey, $expiration, function () use ($context, $locale) {
            return $this->getJournalKeywords($context->getId(), $locale);
        });
    }

    private function getJournalKeywords(int $journalId, string $locale): string
    {
        $publicationIds = Repo::publication()
            ->getCollector()
            ->filterByContextIds([$journalId])
            ->getQueryBuilder()
            ->whereIn('p.status', [Submission::STATUS_PUBLISHED])
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
        // otherwise have its key reindexed by array_slice and reach the cloud as
        // "0", "1", ... instead of the word itself.
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

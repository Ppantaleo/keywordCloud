<?php

/**
 * @file SettingsForm.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 *
 * @brief Form for editors to restrict the keyword cloud to a period of years
 */

namespace APP\plugins\blocks\keywordCloud;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    public function __construct(private KeywordCloudBlockPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        // 0 is stored for "no bound", but the matching option in the select has
        // an empty value, so map it back or nothing would appear as selected.
        $yearStart = (int) $this->plugin->getSetting($this->contextId, 'yearStart');
        $yearEnd = (int) $this->plugin->getSetting($this->contextId, 'yearEnd');

        $this->setData('yearStart', $yearStart ?: '');
        $this->setData('yearEnd', $yearEnd ?: '');
        $this->setData('showPeriod', $this->plugin->getSetting($this->contextId, 'showPeriod'));
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(['yearStart', 'yearEnd', 'showPeriod']);

        // The empty option of each select means "no bound"; normalise it to 0 so
        // the plugin only ever deals with integers.
        $this->setData('yearStart', (int) $this->getData('yearStart'));
        $this->setData('yearEnd', (int) $this->getData('yearEnd'));

        $this->addCheck(new FormValidatorCustom(
            $this,
            'yearEnd',
            'optional',
            'plugins.block.keywordCloud.settings.invalidRange',
            function ($yearEnd) {
                $yearStart = (int) $this->getData('yearStart');
                // Either bound may be left open; only a fully specified range
                // can be inverted.
                return !$yearStart || !$yearEnd || $yearStart <= $yearEnd;
            }
        ));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $years = $this->plugin->getPublishedYears($this->contextId);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            // '' is the "no bound" option, which is what keeps the default
            // behaviour (the whole journal) available.
            'yearOptions' => ['' => __('plugins.block.keywordCloud.settings.noLimit')]
                + array_combine($years, $years),
        ]);

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, 'yearStart', (int) $this->getData('yearStart'), 'int');
        $this->plugin->updateSetting($this->contextId, 'yearEnd', (int) $this->getData('yearEnd'), 'int');
        $this->plugin->updateSetting($this->contextId, 'showPeriod', (bool) $this->getData('showPeriod'), 'bool');

        parent::execute(...$functionArgs);
    }
}

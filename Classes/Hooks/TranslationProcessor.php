<?php
namespace Localizationteam\L10nmgr\Hooks;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2018 KO-Web | Kai Ole Hartwig <mail@ko-web.net>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Localizationteam\L10nmgr\Model\L10nBaseService;
use Localizationteam\L10nmgr\Model\L10nConfiguration;
use Localizationteam\L10nmgr\Model\TranslationData;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extensionmanager\Utility\ConfigurationUtility;

class TranslationProcessor
{
    /**
     * @var array
     */
    private $localizedRecords = [];

    /**
     * @var array
     */
    private $referenceRecords = [];

    /**
     * @var \TYPO3\CMS\Extensionmanager\Utility\ConfigurationUtility
     */
    protected $configurationUtility = null;

    /**
     * Remap orphans
     *
     * @param \Localizationteam\L10nmgr\Model\L10nConfiguration $configurationObject
     * @param \Localizationteam\L10nmgr\Model\TranslationData $translationData
     * @param \Localizationteam\L10nmgr\Model\L10nBaseService $l10nBaseService
     *
     * @return void
     */
    public function processBeforeSaving(
        L10nConfiguration $configurationObject,
        TranslationData $translationData,
        L10nBaseService $l10nBaseService
    ) {
        if ($l10nBaseService->getImportAsDefaultLanguage()) {
            return;
        }

        $targetLanguage = $translationData->getLanguage();
        $inputArray = $translationData->getTranslationData();

        $automaticUnHide = (bool)$this->getExtensionConfiguration('l10nmgr', 'enable_neverHideAtCopy');

        $remappedInputArray = $this->remapNonMatchingIds($inputArray, $targetLanguage, true, $automaticUnHide);

        $translationData->setTranslationData($remappedInputArray);
    }

    /**
     * @param array $translationData
     * @param int $targetLanguage
     * @param bool $preTranslateMissingReferences
     * @param bool $automaticUnHide
     *
     * @return array
     */
    protected function remapNonMatchingIds(
        array $translationData,
        $targetLanguage,
        $preTranslateMissingReferences = false,
        $automaticUnHide = false
    ) {
        $remappedInputArray = [];
        $preTranslateCommands = [];
        $helperArray = [];

        // For each table with elements to be translated.
        foreach ($translationData as $table => $elementsInTable) {
            // For each element to translate from that table.
            foreach ($elementsInTable as $elementUid => $fields) {
                // For each field to translate.
                foreach ($fields as $fieldKey => $translatedValue) {
                    // Check if the translated record is the right translated record.
                    list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $fieldKey);

                    if (MathUtility::canBeInterpretedAsInteger($TuidString) && intval($TuidString) > 0) {
                        $translatedRecordUid = $this->getLocalizedUid($Ttable, $elementUid, $targetLanguage);

                        /*
                         * Only remap when the translation data points to a non existent record and there is already a
                         * localized record.
                         */
                        if ($translatedRecordUid > 0 && intval($TuidString) !== $translatedRecordUid) {
                            // Remap missing translated record to current translated record.
                            $fieldKey = $Ttable . ':' . $translatedRecordUid . ':' . $Tfield;
                            if ($Tpath) {
                                $fieldKey .= ':' . $Tpath;
                            }
                        } else {
                            // Record doesn't exist, let it be created again by using "NEW/lang/origUid" notation.
                            $translatedRecordUid = 'NEW/' . $targetLanguage . '/' . $elementUid;
                            $fieldKey = $Ttable . ':' . $translatedRecordUid . ':' . $Tfield;
                            if ($Tpath) {
                                $fieldKey .= ':' . $Tpath;
                            }

                            /*
                             * Pre-translate file references in case that only the reference and not the whole content
                             * element was deleted. If the parent translated element was deleted, localize the entire
                             * parent element and mark both parent and relation for remapping.
                             */
                            if ($preTranslateMissingReferences && $table === 'sys_file_reference') {
                                $referenceRecord = $this->getReferenceRecord($elementUid);

                                if (!is_null($referenceRecord)) {
                                    $parentTable = $referenceRecord['tablenames'];
                                    $parentUid = $referenceRecord['uid_foreign'];

                                    $translatedParentUid = $this->getLocalizedUid(
                                        $parentTable,
                                        $parentUid,
                                        $targetLanguage
                                    );

                                    if ($translatedParentUid > 0) {
                                        /*
                                         * Only the translated file reference is missing, pre-translate it by using
                                         * field synchronization.
                                         */
                                        $preTranslateCommands[$parentTable][$parentUid]['inlineLocalizeSynchronize'] = [
                                            'field' => $referenceRecord['fieldname'],
                                            'language' => $targetLanguage,
                                            'action' => 'synchronize',
                                        ];
                                    } else {
                                        /*
                                         * Both the translated file reference and its translated parent element are
                                         * missing. Issue a localization command for the parent element and remap all
                                         * affected entries after executing the command map with DataHandler.
                                         */
                                        $preTranslateCommands[$parentTable][$parentUid]['localize'] = $targetLanguage;
                                        $helperArray[$parentTable][$parentUid] = true;
                                    }

                                    $helperArray[$table][$elementUid][$fieldKey] = true;
                                }
                            }
                        }
                    }

                    $remappedInputArray[$table][$elementUid][$fieldKey] = $translatedValue;
                }
            }
        }

        if ($preTranslateMissingReferences && !empty($preTranslateCommands)) {
            // Pre-translate content for missing elements and get mapping array from DataHandler.
            $copyMappingArray = $this->executeCommandMap($preTranslateCommands, $automaticUnHide);

            foreach ($helperArray as $table => $elementsInTable) {
                foreach ($elementsInTable as $elementUid => $fields) {
                    // When all the fields of the record were marked for remap.
                    if ($fields === true) {
                        $fields = $remappedInputArray[$table][$elementUid];
                    }

                    foreach ($fields as $fieldKey => $translatedValue) {
                        if (isset($copyMappingArray[$table][$elementUid])) {
                            $mappedCopiedId = (int)$copyMappingArray[$table][$elementUid];

                            if ($mappedCopiedId > 0) {
                                list($Ttable, $TuidString, $Tfield, $Tpath) = explode(':', $fieldKey);

                                $newFieldKey = $Ttable . ':' . $mappedCopiedId . ':' . $Tfield;
                                if ($Tpath) {
                                    $newFieldKey .= ':' . $Tpath;
                                }

                                $remappedInputArray[$table][$elementUid][$newFieldKey] =
                                    $remappedInputArray[$table][$elementUid][$fieldKey];

                                unset($remappedInputArray[$table][$elementUid][$fieldKey]);
                            }
                        }
                    }
                }
            }
        }

        return $remappedInputArray;
    }

    /**
     * @param string $table
     * @param int $originalUid
     * @param int $targetLanguage
     *
     * @return int
     */
    protected function getLocalizedUid($table, $originalUid, $targetLanguage)
    {
        $cacheKey = $table . ':' . $originalUid . ':' . $targetLanguage;
        $localizedUid = 0;

        if (array_key_exists($cacheKey, $this->localizedRecords)) {
            $localizedUid = $this->localizedRecords[$cacheKey];
        } else {
            $translatedRecords = BackendUtility::getRecordLocalization($table, $originalUid, $targetLanguage);

            if ($translatedRecords !== false && !empty($translatedRecords)) {
                $translatedRecord = reset($translatedRecords);

                if (is_array($translatedRecord) && array_key_exists('uid', $translatedRecord)) {
                    $localizedUid = (int)$translatedRecord['uid'];
                }
            }

            $this->localizedRecords[$cacheKey] = $localizedUid;
        }

        return $localizedUid;
    }

    /**
     * @param int $elementUid
     * @return array|null
     */
    protected function getReferenceRecord($elementUid)
    {
        $elementUid = (int)$elementUid;

        if (!array_key_exists($elementUid, $this->referenceRecords)) {
            $this->referenceRecords[$elementUid] = BackendUtility::getRecord(
                'sys_file_reference',
                $elementUid,
                'uid_foreign,tablenames,fieldname'
            );
        }

        return $this->referenceRecords[$elementUid];
    }

    /**
     * @param $preTranslateCommands
     * @param bool $automaticUnHide
     * @return array
     */
    protected function executeCommandMap($preTranslateCommands, $automaticUnHide = false)
    {
        /** @var \TYPO3\CMS\Core\DataHandling\DataHandler $dataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

        $dataHandler->neverHideAtCopy = boolval($automaticUnHide);
        $dataHandler->isImporting = true;
        $dataHandler->start([], $preTranslateCommands);
        $dataHandler->process_cmdmap();

        if (count($dataHandler->errorLog)) {
            $beUser = $this->getBackendUser();

            if (!is_null($beUser)) {
                $beUser->writelog(4, 0, 2, 1519749045, '[l10nmgr_ext] TCEmain localization errors', $dataHandler->errorLog);
            }
        }

        return $dataHandler->copyMappingArray_merged;
    }

    /**
     * Returns the current BE user.
     *
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @param string $extKey
     * @param string $parameterName
     * @return mixed
     */
    protected function getExtensionConfiguration($extKey, $parameterName)
    {
        if (is_null($this->configurationUtility)) {
            /** @var \TYPO3\CMS\Extbase\Object\ObjectManager $objectManager */
            $objectManager = GeneralUtility::makeInstance(ObjectManager::class);

            $this->configurationUtility = $objectManager->get(ConfigurationUtility::class);
        }

        $extensionConfiguration = $this->configurationUtility->getCurrentConfiguration($extKey);

        return $extensionConfiguration[$parameterName]['value'] ?: null;
    }
}

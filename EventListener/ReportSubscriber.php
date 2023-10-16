<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Query\QueryBuilder;
use Mautic\FormBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\CompanyReportData;
use Mautic\LeadBundle\Report\FieldsBuilder;
use Mautic\ReportBundle\Event\ColumnCollectEvent;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportDataEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\Helper\ReportHelper;
use Mautic\ReportBundle\ReportEvents;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItemXrefContact;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Report\ReportColumnsBuilder;
use MauticPlugin\CustomObjectsBundle\Repository\CustomObjectRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ReportSubscriber implements EventSubscriberInterface
{
    public const CUSTOM_OBJECTS_CONTEXT_GROUP    = 'custom.objects';
    public const CHILD_CUSTOM_OBJECT_NAME_PREFIX = '&nbsp;&nbsp;&nbsp;&nbsp;';

    public const CUSTOM_ITEM_TABLE_ALIAS                  = 'ci';
    public const PARENT_CUSTOM_ITEM_TABLE_ALIAS           = 'pci';
    public const CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_ALIAS = 'cixci';
    public const CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_NAME  = 'custom_item_xref_custom_item';
    public const CUSTOM_ITEM_XREF_CONTACT_TABLE_ALIAS     = 'cil';
    public const CONTACTS_COMPANIES_XREF                  = 'cic';
    public const LEADS_TABLE_ALIAS                        = 'l';
    public const USERS_TABLE_ALIAS                        = 'u';
    public const COMPANIES_TABLE_ALIAS                    = 'comp';

    /**
     * @var ArrayCollection
     */
    private $customObjects;

    /**
     * @var CustomObjectRepository
     */
    private $customObjectRepository;

    /**
     * @var FieldsBuilder
     */
    private $fieldsBuilder;

    /**
     * @var CompanyReportData
     */
    private $companyReportData;

    /**
     * @var ReportHelper
     */
    private $reportHelper;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    private FieldModel $fieldModel;

    public function __construct(
        CustomObjectRepository $customObjectRepository,
        FieldsBuilder $fieldsBuilder,
        CompanyReportData $companyReportData,
        ReportHelper $reportHelper,
        TranslatorInterface $translator,
        FieldModel $fieldModel,
        private CustomItemModel $customItemModel,
        private CustomObjectModel $customObjectModel,
        private CustomFieldModel $customFieldModel,
        private RouterInterface $router,
        private RequestStack $requestStack
    ) {
        $this->customObjectRepository = $customObjectRepository;
        $this->fieldsBuilder          = $fieldsBuilder;
        $this->companyReportData      = $companyReportData;
        $this->reportHelper           = $reportHelper;
        $this->translator             = $translator;
        $this->fieldModel             = $fieldModel;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD           => ['onReportBuilder', 0],
           ReportEvents::REPORT_ON_COLUMN_COLLECT  => ['onReportColumnCollect', 0],
            ReportEvents::REPORT_ON_GENERATE        => [
                ['onReportGenerate', 0],
                ['onFormResultReportGenerate', -1],
            ],
            ReportEvents::REPORT_ON_DISPLAY         => ['onReportDisplay', 0],
        ];
    }

    private function getCustomObjects(): ArrayCollection
    {
        if ($this->customObjects instanceof ArrayCollection) {
            return $this->customObjects;
        }

        $allCustomObjects    = new ArrayCollection($this->customObjectRepository->findAll());
        $parentCustomObjects = $allCustomObjects->filter(function (CustomObject $customObject): bool {
            return CustomObject::TYPE_MASTER === $customObject->getType();
        });
        $parentCustomObjects = $this->sortCustomObjects($parentCustomObjects);

        $this->customObjects = new ArrayCollection();
        foreach ($parentCustomObjects as $parentCustomObject) {
            $this->customObjects->add($parentCustomObject);
            $childCustomObject = $allCustomObjects->filter(function (CustomObject $childCustomObject) use ($parentCustomObject): bool {
                return $childCustomObject->getMasterObject() ?
                    $parentCustomObject->getId() === $childCustomObject->getMasterObject()->getId()
                    :
                    false;
            })->first();

            if (!$childCustomObject) {
                continue;
            }

            $childCustomObject->setNamePlural(self::CHILD_CUSTOM_OBJECT_NAME_PREFIX.$childCustomObject->getNamePlural());
            $this->customObjects->add($childCustomObject);
        }

        return $this->customObjects;
    }

    private function sortCustomObjects(ArrayCollection $customObjects): ArrayCollection
    {
        $customObjects = $customObjects->toArray();
        usort($customObjects, function (CustomObject $a, CustomObject $b): int {
            return strnatcmp($a->getNamePlural(), $b->getNamePlural());
        });

        return new ArrayCollection($customObjects);
    }

    private function getContext(CustomObject $customObject): string
    {
        return static::CUSTOM_OBJECTS_CONTEXT_GROUP.'.'.$customObject->getId();
    }

    private function getContexts(): array
    {
        return $this->getCustomObjects()
            ->map(function (CustomObject $customObject) {
                return $this->getContext($customObject);
            })
            ->toArray();
    }

    private function getStandardColumns(string $prefix)
    {
        return $this->reportHelper->getStandardColumns($prefix, ['description', 'publish_up', 'publish_down']);
    }

    private function addPrefixToColumnLabel(array $columns, string $prefix): array
    {
        foreach ($columns as $alias => $column) {
            $columnLabel = $this->translator->trans($columns[$alias]['label']);
            if (0 === strpos($columnLabel, $prefix)) {
                $columns[$alias]['label'] = $columnLabel;
                // Don't add the prefix twice
                continue;
            }

            $columns[$alias]['label'] = sprintf(
                '%s %s',
                str_replace(self::CHILD_CUSTOM_OBJECT_NAME_PREFIX, '', $prefix),
                $columnLabel
            );
        }

        return $columns;
    }

    private function getCustomObjectColumns(CustomObject $customObject, string $customItemTablePrefix): array
    {
        $standardColumns     = $this->getStandardColumns($customItemTablePrefix);
        $customFieldsColumns = (new ReportColumnsBuilder($customObject))->getColumns();
        $columns = array_merge($standardColumns, $customFieldsColumns);
        $columns = $this->addLinkToCustomObjectColumns($columns);
        return $columns;
    }

    /**
     * Add custom object specific report sources and columns.
     */
    public function onReportBuilder(ReportBuilderEvent $event): void
    {
        if (!$event->checkContext($this->getContexts())) {
            return;
        }

        $companyColumns = $this->getCompanyColumns();
        $contactColumns = $this->addPrefixToColumnLabel(
            $this->getLeadColumns(),
            $this->translator->trans('mautic.lead.contact')
        );

        /** @var CustomObject $customObject */
        foreach ($this->getCustomObjects() as $customObject) {
            $customObjectColumns       = $this->getCustomObjectColumns($customObject, static::CUSTOM_ITEM_TABLE_ALIAS.'.');
            $parentCustomObjectColumns = [];

            if ($customObject->getMasterObject()) {
                $parentCustomObjectColumns = $this->addPrefixToColumnLabel(
                    $this->getCustomObjectColumns(
                        $customObject->getMasterObject(),
                        static::PARENT_CUSTOM_ITEM_TABLE_ALIAS.'.'
                    ),
                    $customObject->getMasterObject()->getNameSingular()
                );
            }

            $columns = array_merge(
                $this->addPrefixToColumnLabel($customObjectColumns, $customObject->getNameSingular()),
                $contactColumns,
                $companyColumns,
                $parentCustomObjectColumns
            );

            $event->addTable(
                $this->getContext($customObject),
                [
                    'display_name' => $customObject->getNamePlural(),
                    'columns'      => $columns,
                ],
                static::CUSTOM_OBJECTS_CONTEXT_GROUP
            );
        }
    }

    /**
     * Add custom object specific report columns.
     * is triggered during the onReportBuilder in the ReportSubscriber from the ReportBundle
     */
    public function onReportColumnCollect(ColumnCollectEvent $event): void
    {
        $object = $event->getObject();

        if (!$this->customObjectRepository->checkAliasExists($object)) {
            return;
        }

        $customObject         = $this->customObjectRepository->findOneBy(['alias' => $object]);
        $properties           = $event->getProperties();
        $customItemTableAlias = static::CUSTOM_ITEM_TABLE_ALIAS.'_'.$customObject->getId();
        $customObjectColumns  = $this->getCustomObjectColumns($customObject, $customItemTableAlias.'.');

        array_walk(
            $customObjectColumns,
            function (&$item, $index) use ($customObject, $properties) {
                $item['idCustomObject'] = $customObject->getId();
                $item                   = array_merge($item, $properties);
            }
        );

        $columns = array_merge(
            $columns ?? [],
            $this->addPrefixToColumnLabel($customObjectColumns, $customObject->getNameSingular()),
            $parentCustomObjectColumns ?? []
        );

        $event->addColumns($columns);
    }

    private function getLeadColumns(): array
    {
        return $this->fieldsBuilder->getLeadFieldsColumns(static::LEADS_TABLE_ALIAS.'.');
    }

    private function getCompanyColumns(): array
    {
        $companyColumns = $this->companyReportData->getCompanyData();
        // We don't need this column because we fetch company/lead relationships via custom objects
        unset($companyColumns['companies_lead.is_primary'], $companyColumns['companies_lead.date_added']);

        return $companyColumns;
    }

    private function addTablePrefix(string $table): string
    {
        return MAUTIC_TABLE_PREFIX.$table;
    }

    private function initializeCustomObject(string $context): CustomObject
    {
        $customObjectId = explode('.', $context);
        $customObjectId = (int) end($customObjectId);

        if (1 > $customObjectId) {
            throw new \Exception('Custom Object ID is not defined.');
        }

        /** @var CustomObject $customObject */
        $customObject = $this->customObjectRepository->find($customObjectId);
        if (!($customObject instanceof CustomObject)) {
            throw new \Exception('Custom Object doesn\'t exist');
        }

        return $customObject;
    }

    private function joinLeadColumns(QueryBuilder $queryBuilder): void
    {
        // Joining contacts tables
        $contactsJoinCondition = sprintf('%s.id = %s.custom_item_id', static::CUSTOM_ITEM_TABLE_ALIAS, static::CUSTOM_ITEM_XREF_CONTACT_TABLE_ALIAS);
        $queryBuilder->leftJoin(static::CUSTOM_ITEM_TABLE_ALIAS, $this->addTablePrefix(CustomItemXrefContact::TABLE_NAME), static::CUSTOM_ITEM_XREF_CONTACT_TABLE_ALIAS, $contactsJoinCondition);
        $contactsTableJoinCondition = sprintf('%s.contact_id = %s.id', static::CUSTOM_ITEM_XREF_CONTACT_TABLE_ALIAS, static::LEADS_TABLE_ALIAS);
        $queryBuilder->leftJoin(static::CUSTOM_ITEM_XREF_CONTACT_TABLE_ALIAS, $this->addTablePrefix('leads'), static::LEADS_TABLE_ALIAS, $contactsTableJoinCondition);
    }

    private function joinUsersColumns(QueryBuilder $queryBuilder): void
    {
        $usersJoinCondition = sprintf('%s.id = %s.owner_id', static::USERS_TABLE_ALIAS, static::LEADS_TABLE_ALIAS);
        $queryBuilder->leftJoin(static::LEADS_TABLE_ALIAS, $this->addTablePrefix('users'), static::USERS_TABLE_ALIAS, $usersJoinCondition);
    }

    private function joinCompanyColumns(QueryBuilder $queryBuilder): void
    {
        // Joining companies tables
        $companiesJoinCondition = sprintf('%s.id = %s.lead_id', static::LEADS_TABLE_ALIAS, static::CONTACTS_COMPANIES_XREF);
        $queryBuilder->leftJoin(static::LEADS_TABLE_ALIAS, $this->addTablePrefix('companies_leads'), static::CONTACTS_COMPANIES_XREF, $companiesJoinCondition);
        $companiesTableJoinCondition = sprintf('%s.company_id = %s.id', static::CONTACTS_COMPANIES_XREF, static::COMPANIES_TABLE_ALIAS);
        $queryBuilder->leftJoin(static::CONTACTS_COMPANIES_XREF, $this->addTablePrefix('companies'), static::COMPANIES_TABLE_ALIAS, $companiesTableJoinCondition);
    }

    private function joinParentCustomObjectStandardColumns(QueryBuilder $queryBuilder): void
    {
        // Joining companies tables
        $relationshipTableJoinCondition = sprintf('%s.id = %s.custom_item_id_higher', static::CUSTOM_ITEM_TABLE_ALIAS, self::CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_ALIAS);
        $queryBuilder->leftJoin(static::CUSTOM_ITEM_TABLE_ALIAS, $this->addTablePrefix(self::CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_NAME), static::CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_ALIAS, $relationshipTableJoinCondition);
        $parentCustomItemTableJoinCondition = sprintf('%s.custom_item_id_lower = %s.id', static::CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_ALIAS, static::PARENT_CUSTOM_ITEM_TABLE_ALIAS);
        $queryBuilder->leftJoin(static::CUSTOM_ITEM_XREF_CUSTOM_ITEM_TABLE_ALIAS, $this->addTablePrefix(CustomItem::TABLE_NAME), static::PARENT_CUSTOM_ITEM_TABLE_ALIAS, $parentCustomItemTableJoinCondition);
    }

    /**
     * Initialize the QueryBuilder object to generate reports from.
     * Not used for the form results. Check onFormResultReportGenerate for that.
     */
    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if (!$event->checkContext($this->getContexts())) {
            return;
        }

        $customObject = $this->initializeCustomObject($event->getContext());

        $queryBuilder = $event->getQueryBuilder();
        $queryBuilder->from($this->addTablePrefix(CustomItem::TABLE_NAME), static::CUSTOM_ITEM_TABLE_ALIAS);
        $queryBuilder->andWhere($queryBuilder->expr()->eq(static::CUSTOM_ITEM_TABLE_ALIAS.'.custom_object_id', $customObject->getId()));

        $event->applyDateFilters($queryBuilder, 'date_added', static::CUSTOM_ITEM_TABLE_ALIAS);

        $userColumns = [
            static::USERS_TABLE_ALIAS.'.first_name',
            static::USERS_TABLE_ALIAS.'.last_name',
        ];

        $usesLeadsColumns    = $event->usesColumn(array_keys($this->getLeadColumns()));
        $usesUserColumns     = $event->usesColumn($userColumns);
        $usesIpAddressColumn = $event->usesColumn('i.ip_address');

        if ($usesLeadsColumns || $usesUserColumns || $usesIpAddressColumn) {
            $this->joinLeadColumns($queryBuilder);
        }

        if ($usesUserColumns) {
            $this->joinUsersColumns($queryBuilder);
        }

        if ($usesIpAddressColumn) {
            $event->addLeadIpAddressLeftJoin($queryBuilder);
        }

        if ($this->companyReportData->eventHasCompanyColumns($event)) {
            $this->joinCompanyColumns($queryBuilder);
        }

        // Join custom objects tables
        $reportColumnsBuilder = new ReportColumnsBuilder($customObject);
        $reportColumnsBuilder->setFilterColumnsCallback([$event, 'usesColumn']);
        $reportColumnsBuilder->joinReportColumns($queryBuilder, static::CUSTOM_ITEM_TABLE_ALIAS);

        $parentCustomObject = $customObject->getMasterObject();
        if (!($parentCustomObject instanceof CustomObject)) {
            return;
        }

        $standardParentCustomObjectColumns = $this->getStandardColumns(static::PARENT_CUSTOM_ITEM_TABLE_ALIAS.'.');
        $parentCustomObjectColumns         = (new ReportColumnsBuilder($customObject->getMasterObject()))->getColumns();

        $parentCustomObjectColumns = array_keys(array_merge($parentCustomObjectColumns, $standardParentCustomObjectColumns));

        $usesParentCustomObjectsColumns = false;
        foreach ($parentCustomObjectColumns as $column) {
            if ($event->usesColumn($column)) {
                $usesParentCustomObjectsColumns = true;
                break;
            }
        }

        if (!$usesParentCustomObjectsColumns) {
            // No need to join parent custom object columns if we don't use them
            return;
        }

        $this->joinParentCustomObjectStandardColumns($queryBuilder);

        // Join parent custom objects tables
        $parentCustomObjectReportColumnsBuilder = new ReportColumnsBuilder($parentCustomObject);
        $parentCustomObjectReportColumnsBuilder->setFilterColumnsCallback([$event, 'usesColumn']);
        $parentCustomObjectReportColumnsBuilder->joinReportColumns($queryBuilder, static::PARENT_CUSTOM_ITEM_TABLE_ALIAS);
    }

    public function onFormResultReportGenerate(ReportGeneratorEvent $event): void
    {
        $contextFormResult     = 'form.results';
        $prefixFormResultTable = 'fr';
        $context               = $event->getContext();

        if (!str_starts_with($context, $contextFormResult)) {
            return;
        }

        $addedCustomObjects = [];
        $columns            = array_filter(
            $event->getOptions()['columns'],
            function ($elem) use (&$addedCustomObjects) {
                // left one column for each custom object. why?
                $addToColumnList    = key_exists('idCustomObject', $elem) && !in_array($elem['idCustomObject'], $addedCustomObjects);
                $addedCustomObjects[] = $elem['idCustomObject'] ?? '';

                return $addToColumnList;
            }
        );

        $queryBuilder = $event->getQueryBuilder();

        foreach ($columns as $column) {

            $customObject          = $this->customObjectRepository->find($column['idCustomObject']);
            $field                 = $this->fieldModel->getEntity($column['formFieldId']);

            $customItemTableAlias  = static::CUSTOM_ITEM_TABLE_ALIAS.'_'.$customObject->getId();

            $colCustomObjectName   = sprintf('`%s`.`id`', $customItemTableAlias);
            $colMappedField        = sprintf('`%s`.`%s`', $prefixFormResultTable, $field->getAlias());
            $colCustomItemObjectId = sprintf('`%s`.`custom_object_id`', $customItemTableAlias);
            $colCustomObjectId     = sprintf('%s', $customObject->getId());

            $joinCondition         = $field->hasChoices()
                ? "FIND_IN_SET({$colCustomObjectName}, REPLACE({$colMappedField}, ' ', '')) > 0 AND {$colCustomItemObjectId} = {$colCustomObjectId}"
                : "{$colMappedField} = {$colCustomObjectName} AND {$colCustomItemObjectId} = {$colCustomObjectId}";
            $queryBuilder->leftJoin($prefixFormResultTable, CustomItem::TABLE_NAME, $customItemTableAlias, $joinCondition);

            $addedCustomObjects[]  = $column['idCustomObject'];
            $reportColumnsBuilder  = new ReportColumnsBuilder($customObject);
            $reportColumnsBuilder->setFilterColumnsCallback([$event, 'usesColumn']);
            $reportColumnsBuilder->joinReportColumns($queryBuilder, $customItemTableAlias);
        }
    }

    public function onReportDisplay(ReportDataEvent $event): void
    {
        $data       = $event->getData();
        $dataMeta   = $event->getDataMeta();

        $columns    = $event->getOptions()['columns'];
        $customObjectColumns = $this->filterCustomObjectColumns($columns);

        foreach ($data as $rowIndex => &$dataItem) {
            foreach ($dataItem as $dataColumn => $value) {

                if (!empty($value)) {
                    // $column = the column alias with the prefix
                    foreach ($customObjectColumns as $column => $customObjectColumn) {
                        if ($customObjectColumn['alias'] === $dataColumn) {
                            try {
                                $object = $this->customObjectModel->fetchEntityByAlias($customObjectColumn['mappedObject']);
                            } catch (NotFoundException $e) {
                                // Do nothing if the custom object doesn't exist.
                                return;
                            }

                            // if it is not an ID, just use the original value. e.g. the name
                            if (empty($customObjectColumn['link'])) {
                                $newValue = $value;
                                continue;
                            }

                            $ids      = explode(',', $value);
                            $newValue = '';
                            if ($customObjectColumn['mappedField'] === $object->getAlias()) {
                                // @todo how to fix the mapping problem? 
                                // a field with the name has to be queried by name, not by id
                                foreach ($ids as $id) {
                                    try {
                                        $customItem = $this->customItemModel->fetchEntity((int) $id);

                                        // set the value to be displayed in the report. If its a link on an ID, use the original value
                                        if (!empty($customObjectColumn['link'] && $customObjectColumn['type']==='int')) {
                                            $newValue .= empty($newValue) ? $value : ', '.$value;
                                        } else {
                                            $newValue .= empty($newValue) ? $customItem->getName() : ', '.$customItem->getName();
                                        }

                                        // @todo handle the case where there are multiple values in one cell
                                        $dataMeta[$rowIndex][$column] = $this->setDataMeta($customItem);
                                    } catch (NotFoundException $e) {
                                        // Do nothing if the custom item doesn't exist anymore.
                                    }
                                }
                            } else {
                                // @todo when do we need this else case?
                                foreach ($ids as $id) {
                                    try {
                                        $customItem = $this->customItemModel->fetchEntity((int) $id);
                                        $itemWithCustomFieldValues = $this->customItemModel->populateCustomFields($customItem);
                                        $itemCustomFieldsValues    = $itemWithCustomFieldValues->getCustomFieldValues();

                                        foreach ($itemCustomFieldsValues as $customFieldValue) {
                                            if ($customObjectColumn['mappedField'] === $customFieldValue->getCustomField()->getAlias()) {

                                                // set the value to display
                                                $newValue .= empty($newValue) ? $customItem->getName() : ', '.$customItem->getName();
                                            }
                                            $dataMeta[$rowIndex][$column] = $this->setDataMeta($customItem);
                                        }
                                    } catch (NotFoundException $e) {
                                        // Do nothing if the custom item doesn't exist anymore.
                                    }
                                }
                            }
                            // should that maybe be $column too? So it is unuqiue?
                            $dataItem[$dataColumn] = $newValue;
                        }
                    }
                }
            }
        }

        $event->setData($data);
        $event->setDataMeta($dataMeta);
    }

    /**
     * Filter the list of all columns to only include the ones that are mapped
     * to a custom object.
     */
    private function filterCustomObjectColumns(array $columns): array
    {
        $objectArr = [];

        $objects = $this->customObjectRepository->getEntities();

        foreach ($objects as $object) {
            $objectArr[] = $object->getAlias();
        }

        return array_filter(
            $columns,
            fn ($item) => isset($item['mappedObject']) && in_array($item['mappedObject'], $objectArr ?? [])
        );
    }

    /**
     * Add the necessary parameters so that the field gets a link in the UI
     * to the corresponding custom item.
     */
    private function addLinkToCustomObjectColumns(array $columns)
    {
        foreach ($columns as $column => $customObjectColumn) {
            
            // only when the string contains an id, add the link
            if (strpos($column, '.id') === false) {
                continue;
            }
            
            $customObjectColumns[$column] = array_merge(
                $customObjectColumn,
                [
                    'link' => 'mautic_custom_item_view',
                    'linkParameters'=> [
                        'itemId'    => '%itemId%',
                        'objectId'   => '%objectId%'
                    ],
                ]
            );
        }
        $mergedColumns = array_merge(
            $columns,
            $customObjectColumns
        );
        return $mergedColumns;
    }

    /**
     * Set acompanying data to be used in the report. E.g. for links
     */
    private function setDataMeta(CustomItem $item): array
    {
        return [
            "itemId"  => $item->getId(),
            "objectId"=> $item->getCustomObject()->getId(),
        ];
    }
}

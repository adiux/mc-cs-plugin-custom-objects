<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\EventListener;

use Mautic\FormBundle\Crate\ObjectCrate;
use Mautic\FormBundle\Event\FieldCollectEvent;
use Mautic\FormBundle\Event\FieldCollectFilterEvent;
use Mautic\FormBundle\Event\ObjectCollectEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Crate\FieldCrate;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Repository\CustomItemXrefContactRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormSubscriber implements EventSubscriberInterface
{
    private CustomObjectModel $customObjectModel;
    private CustomItemModel $customItemModel;

    public function __construct(
        CustomObjectModel $customObjectModel,
        CustomItemModel $customItemModel,
        CustomItemXrefContactRepository $customItemXrefContactRepository
    ) {
        $this->customObjectModel          = $customObjectModel;
        $this->customItemModel            = $customItemModel;
        $this->customItemXrefContactRepository = $customItemXrefContactRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::ON_OBJECT_COLLECT => ['onObjectCollect', 0],
            FormEvents::ON_FIELD_COLLECT  => ['onFieldCollect', 0],
            FormEvents::ON_FIELD_COLLECT_FILTER  => ['onFieldCollectFilter', 0],
        ];
    }

    public function onObjectCollect(ObjectCollectEvent $event): void
    {
        foreach ($this->customObjectModel->fetchEntities() as $entity) {
            $event->appendObject(new ObjectCrate($entity->getAlias(), $entity->getName()));
        }
    }

    public function onFieldCollect(FieldCollectEvent $event): void
    {
        try {
            $object = $this->customObjectModel->fetchEntityByAlias($event->getObject());
        } catch (NotFoundException $e) {
            // Do nothing if the custom object doesn't exist.
            return;
        }

        $items = $this->customItemModel->fetchCustomItemsForObject($object);

        foreach ($object->getCustomFields() as $field) {
            $list = $this->getCustomFieldValues($field, $items);
            $event->appendField(new FieldCrate($field->getAlias(), $field->getName(), $field->getType(), ['list' => $list]));
        }
    }

    public function onFieldCollectFilter(FieldCollectFilterEvent $event): void
    {
        $object = $event->getObject();
        try {
            $object = $this->customObjectModel->fetchEntityByAlias($object);
        } catch (NotFoundException $e) {
            // Do nothing if the custom object doesn't exist.
            return;
        }

//        $data = $this->customItemModel->getFieldListData($object, $items, $filterEntityType);
        $contactId = $event->getLead()?->getId();

//        $items = $this->customItemModel->fetchCustomItemsForObjectForContact($object, $contactId);
        $items = $this->customItemModel->fetchCustomItemsForObject($object);

        $items = array_filter(
            $items,
            function ($item) use ($contactId) {
                $ids = $this->customItemXrefContactRepository->getContactIdsLinkedToCustomItem((int)$item->getId(), 200, 0);
                $ids = array_column($ids, 'contact_id');
                return in_array($contactId, $ids);
            }
        );

        $event->removeFields();

        foreach ($object->getCustomFields() as $field) {
            $list = $this->getCustomFieldValues($field, $items);

            !empty($list) && $event->appendField(new FieldCrate($field->getAlias(), $field->getName(), $field->getType(), ['list' => $list]));
        }

    }

    /**
     * @return array<string, mixed>
     */
    private function getCustomFieldValues(CustomField $field, array $items): array
    {
        $list = [];

        array_walk(
            $items,
            function ($item) use ($field, &$list) {
                $itemWithCustomFieldValues = $this->customItemModel->populateCustomFields($item);
                $itemCustomFieldsValues    = $itemWithCustomFieldValues->getCustomFieldValues();

                foreach ($itemCustomFieldsValues as $customFieldValue) {
                    if ($field->getAlias() === $customFieldValue->getCustomField()->getAlias()) {
                        $list[$item->getName()] = $customFieldValue->getValue();
                    }
                }
            }
        );

        return $list ?? [];
    }
}

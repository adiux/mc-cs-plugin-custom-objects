<?php

declare(strict_types=1);

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\Tests\Functional\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use MauticPlugin\CustomObjectsBundle\Tests\Functional\DataFixtures\Traits\DatabaseSchemaTrait;
use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\CustomObjectsBundle\Entity\CustomItem;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Tests\Functional\DataFixtures\Traits\CustomObjectsTrait;
use MauticPlugin\CustomObjectsBundle\EventListener\CampaignSubscriber;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;

class CampaignSubscriberTest extends KernelTestCase
{
    use DatabaseSchemaTrait;
    use CustomObjectsTrait;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var CustomItemModel
     */
    private $customItemModel;

    /**
     * @var CustomFieldValueModel
     */
    private $customFieldValueModel;

    /**
     * @var CampaignSubscriber
     */
    private $campaignSubscriber;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->container = static::$kernel->getContainer();

        /** @var EntityManager $entityManager */
        $entityManager = $this->container->get('doctrine.orm.entity_manager');

        /** @var CustomItemModel $customItemModel */
        $customItemModel       = $this->container->get('mautic.custom.model.item');
        $this->customItemModel = $customItemModel;

        /** @var CustomFieldValueModel $customFieldValueModel */
        $customFieldValueModel       = $this->container->get('mautic.custom.model.field.value');
        $this->customFieldValueModel = $customFieldValueModel;

        /** @var CampaignSubscriber $campaignSubscriber */
        $campaignSubscriber       = $this->container->get('custom_item.campaign.subscriber');
        $this->campaignSubscriber = $campaignSubscriber;

        $this->createFreshDatabaseSchema($entityManager);
    }

    public function testTextFieldConditions(): void
    {
        $contact      = $this->createContact('john@doe.email');
        $customObject = $this->createCustomObjectWithAllFields($this->container, 'Campaign test object');
        $customItem   = new CustomItem($customObject);

        $customItem->setName('Campaign test item');

        $this->customFieldValueModel->createValuesForItem($customItem);

        // Set some values
        $textValue = $customItem->findCustomFieldValueForFieldAlias('text-test-field');
        $textValue->setValue('abracadabra');

        $dateValue = $customItem->findCustomFieldValueForFieldAlias('date-test-field');
        $dateValue->setValue('2019-07-17');

        $datetimeValue = $customItem->findCustomFieldValueForFieldAlias('datetime-test-field');
        $datetimeValue->setValue('2019-07-17 12:44:55');

        $multiselectValue = $customItem->findCustomFieldValueForFieldAlias('multiselect-test-field');
        $multiselectValue->setValue(['option_b']);

        // $urlValue = $customItem->findCustomFieldValueForFieldAlias('url-test-field');

        // Save the values
        $this->customItemModel->save($customItem);
        $this->customItemModel->linkEntity($customItem, 'contact', (int) $contact->getId());

        // Test the URL value is empty.
        // Reported as a segment bug https://github.com/mautic-inc/mautic-internal/issues/1781. Uncomment once fixed.
        // $event = $this->createCampaignExecutionEvent(
        //     $contact,
        //     $urlValue->getCustomField()->getId(),
        //     'empty'
        // );

        // $this->campaignSubscriber->onCampaignTriggerCondition($event);
        // $this->assertTrue($event->getResult());

        // Test equals the same text as the field value.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            '=',
            'abracadabra'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test equals the different text as the field value.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            '=',
            'unicorn'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test not equals the different text as the field value.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            '!=',
            'unicorn'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the text value is empty.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'empty'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test the text value is not empty.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            '!empty'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the text value starts with abra.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'startsWith',
            'abra'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the text value starts with unicorn.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'startsWith',
            'unicorn'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test the text value ends with abra.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'endsWith',
            'cadabra'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the text value emnds with unicorn.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'endsWith',
            'unicorn'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test the text value contains abra.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'contains',
            'cada'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the text value contains unicorn.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $textValue->getCustomField()->getId(),
            'contains',
            'unicorn'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test the date value less than 2019-08-05.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $dateValue->getCustomField()->getId(),
            'lt',
            '2019-08-05'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the date value less than 2019-06-05.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $dateValue->getCustomField()->getId(),
            'lt',
            '2019-06-05'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test the date value greater than 2019-08-05.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $dateValue->getCustomField()->getId(),
            'gt',
            '2019-08-05'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertFalse($event->getResult());

        // Test the date value greater than 2019-06-05.
        $event = $this->createCampaignExecutionEvent(
            $contact,
            $dateValue->getCustomField()->getId(),
            'gt',
            '2019-06-05'
        );

        $this->campaignSubscriber->onCampaignTriggerCondition($event);
        $this->assertTrue($event->getResult());

        // Test the multiselect value less than 2019-08-05.
        // This is throwing 'Array to string conversion' exception. Have to investigate further.
        // $event = $this->createCampaignExecutionEvent(
        //     $contact,
        //     $multiselectValue->getCustomField()->getId(),
        //     'in',
        //     ['option_b']
        // );

        // $this->campaignSubscriber->onCampaignTriggerCondition($event);
        // $this->assertTrue($event->getResult());

        // Test the multiselect value less than 2019-06-05.
        // $event = $this->createCampaignExecutionEvent(
        //     $contact,
        //     $multiselectValue->getCustomField()->getId(),
        //     'in',
        //     ['option_a']
        // );

        // $this->campaignSubscriber->onCampaignTriggerCondition($event);
        // $this->assertFalse($event->getResult());
    }

    /**
     * @param Lead   $contact
     * @param int    $fieldId
     * @param string $operator
     * @param mixed  $fieldValue
     *
     * @return CampaignExecutionEvent
     */
    private function createCampaignExecutionEvent(
        Lead $contact,
        int $fieldId,
        string $operator,
        $fieldValue = null
    ): CampaignExecutionEvent {
        return new CampaignExecutionEvent(
            [
                'lead'  => $contact,
                'event' => [
                    'type'       => 'custom_item.1.fieldvalue',
                    'properties' => [
                        'field'    => $fieldId,
                        'operator' => $operator,
                        'value'    => $fieldValue,
                    ],
                ],
                'eventDetails'    => [],
                'systemTriggered' => [],
                'eventSettings'   => [],
            ],
            null
        );
    }

    /**
     * @param string $email
     *
     * @return Lead
     */
    private function createContact(string $email): Lead
    {
        /** @var LeadModel $contactModel */
        $contactModel = $this->container->get('mautic.lead.model.lead');
        $contact      = new Lead();
        $contact->setEmail($email);
        $contactModel->saveEntity($contact);

        return $contact;
    }
}

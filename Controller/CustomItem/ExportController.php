<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomItem;

use Mautic\CoreBundle\Controller\AbstractFormController;
use MauticPlugin\CustomObjectsBundle\CustomItemEvents;
use MauticPlugin\CustomObjectsBundle\Event\CustomItemExportSchedulerEvent;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemExportSchedulerModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomFieldValueModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends AbstractFormController
{
    /**
     * @var CustomItemPermissionProvider
     */
    private $permissionProvider;

    private CustomItemExportSchedulerModel $model;

    /**
     * @param CustomObjectModel $customObjectModel
     * @param CustomItemPermissionProvider $permissionProvider
     * @param CustomFieldValueModel $customFieldValueModel
     * @param CustomItemExportSchedulerModel $model
     */
    public function __construct(
        CustomObjectModel            $customObjectModel,
        CustomItemPermissionProvider $permissionProvider,
        CustomFieldValueModel $customFieldValueModel,
        CustomItemExportSchedulerModel $model
    )
    {
        $this->permissionProvider = $permissionProvider;
        $this->model = $model;
    }

    /**
     * @param int $object
     * @return Response
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException
     */
    public function exportAction(int $object): Response
    {
        $this->permissionProvider->canCreate($object);

        $customItemExportScheduler = $this->model->saveEntity($object);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch(
            CustomItemEvents::ON_CUSTOM_ITEM_SCHEDULE_EXPORT,
            new CustomItemExportSchedulerEvent($customItemExportScheduler)
        );

        $this->addFlash('custom.item.export.being.prepared', ['%user_email%' => $this->user->getEmail()]);
        $response['message'] = 'Custom Item export scheduled.';
        $response['flashes'] = $this->getFlashContent();

        return new JsonResponse($response);
    }

    public function downloadExportAction(string $fileName): Response
    {
        //Todo: add permission here

//        $permissions = $this->get('mautic.security')
//            ->isGranted(['lead:leads:viewown', 'lead:leads:viewother'], 'RETURN_ARRAY');
//
//        if (!$permissions['lead:leads:viewown'] && !$permissions['lead:leads:viewother']) {
//            return $this->accessDenied();
//        }

        try {
            return $this->model->getExportFileToDownload($fileName);
        } catch (FileNotFoundException $exception) {
            return $this->notFound();
        }
    }
}

<?php

declare(strict_types=1);

/*
 * @copyright   2019 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomItem;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Service\FlashBag;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomItemModel;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomItemRouteProvider;
use MauticPlugin\CustomObjectsBundle\Provider\SessionProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class BatchDeleteController extends CommonController
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var CustomItemModel
     */
    private $customItemModel;

    /**
     * @var SessionProviderInterface
     */
    private $sessionProvider;

    /**
     * @var CustomItemPermissionProvider
     */
    private $permissionProvider;

    /**
     * @var CustomItemRouteProvider
     */
    private $routeProvider;

    /**
     * @var FlashBag
     */
    private $flashBag;

    public function __construct(
        RequestStack $requestStack,
        CustomItemModel $customItemModel,
        SessionProviderInterface $sessionProvider,
        CustomItemPermissionProvider $permissionProvider,
        CustomItemRouteProvider $routeProvider,
        FlashBag $flashBag
    ) {
        $this->requestStack       = $requestStack;
        $this->customItemModel    = $customItemModel;
        $this->sessionProvider    = $sessionProvider;
        $this->permissionProvider = $permissionProvider;
        $this->routeProvider      = $routeProvider;
        $this->flashBag           = $flashBag;
    }

    public function deleteAction(int $objectId): Response
    {
        $request  = $this->requestStack->getCurrentRequest();
        $itemIds  = json_decode($request->get('ids', '[]'), true);
        $page     = $this->sessionProvider->getPage();
        $notFound = [];
        $denied   = [];
        $deleted  = [];

        foreach ($itemIds as $itemId) {
            try {
                $customItem = $this->customItemModel->fetchEntity((int) $itemId);
                $this->permissionProvider->canDelete($customItem);
                $this->customItemModel->delete($customItem);
                $deleted[] = $itemId;
            } catch (NotFoundException $e) {
                $notFound[] = $itemId;
            } catch (ForbiddenException $e) {
                $denied[] = $itemId;
            }
        }

        if ($deleted) {
            $this->flashBag->add(
                'mautic.core.notice.batch_deleted',
                ['%count%' => count($deleted)]
            );
        }

        if ($notFound) {
            $this->flashBag->add(
                'custom.item.error.items.not.found',
                ['%ids%' => implode(',', $notFound)],
                FlashBag::LEVEL_ERROR
            );
        }

        if ($denied) {
            $this->flashBag->add(
                'custom.item.error.items.denied',
                ['%ids%' => implode(',', $denied)],
                FlashBag::LEVEL_ERROR
            );
        }

        return $this->postActionRedirect(
            [
                'returnUrl'       => $this->routeProvider->buildListRoute($objectId, $page),
                'viewParameters'  => ['objectId' => $objectId, 'page' => $page],
                'contentTemplate' => 'CustomObjectsBundle:CustomItem\List:list',
                'passthroughVars' => [
                    'mauticContent' => 'customItem',
                ],
            ]
        );
    }
}

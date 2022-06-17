<?php

namespace App\Controller;

use App\Controller\Api\MainApiController;
use App\Entity\LocalCollectivity;
use App\Helper\ExportHelper;
use App\Repository\LocalCollectivityRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

class TestController extends MainApiController
{
    /**
     * @Route("/api/back/test")
     */
    public function testBack(): JsonResponse
    {
        $roles = $this->getUser()?->getRoles();
        return $this->json($roles);
    }

    /**
     * @Route("/api/back/test_granted")
     * @IsGranted("ROLE_BO_APPLICATION_LOG_READ", message="No access! Get out!")
     */
    public function testBackGranted(): JsonResponse
    {
        $roles = $this->getUser()?->getRoles();
        return $this->json($roles);
    }

    /**
     * @Route("/api/back/test_forbidden")
     * @IsGranted("ROLE_BO_RIGHT_NOT_EXISTING")
     */
    public function testBackForbidden(): JsonResponse
    {
        $roles = $this->getUser()?->getRoles();
        return $this->json($roles);
    }

    /**
     * @Route("/api/front/test")
     */
    public function testFront(): JsonResponse
    {
        $roles = $this->getUser()?->getRoles();
        return $this->json($roles);
    }

    /**
     * @Route("/api/front/test_granted")
     * @IsGranted("ROLE_FO_ACC_READ", message="No access! Get out!")
     */
    public function testFrontGranted(): JsonResponse
    {
        $roles = $this->getUser()?->getRoles();
        return $this->json($roles);
    }

    /**
     * @Route("/api/front/test_forbidden")
     * @IsGranted("ROLE_FO_RIGHT_NOT_EXISTING")
     */
    public function testFrontForbidden(): JsonResponse
    {
        $roles = $this->getUser()?->getRoles();
        return $this->json($roles);
    }

    /**
     * For testing purpose, will be removed
     * @Route("/api/back/test_export")
     * @IsGranted("ROLE_BO_LOCAL_COLLECTIVITY_READ")
     */
    public function testExport(ExportHelper $export): BinaryFileResponse
    {
        $matchFilters = [
            "id" => ["lc.id"],
            "code" => ["lc.code"],
            "label" => ["lc.label"],
            "is_active" => ["lc.is_active"],
            "comment" => ["lc.comment"],
            "address_1" => ["lc.address_1"],
            "address_2" => ["lc.address_2"],
            "postal_code" => ["lc.postal_code"],
            "password" => ["lc.password"],
            "has_front_access" => ["lc.has_front_access"],
            "iban" => ["lc.iban"],
            "bic" => ["lc.bic"],
            "territeo_local_collectivity_id" => ["lc.territeo_local_collectivity_id"],
            "siren" => ["lc.siren"],
            "observation" => ["lc.observation"],
            "processing" => ["lc.processing"],
            "is_password_to_reset" => ["lc.is_password_to_reset"],
            "last_connection_at" => ["lc.last_connection_at"],
        ];
        [$filter, $orderBys] = $this->extractedFilter();

        /** @var LocalCollectivityRepository $localCollectivityRepository */
        $localCollectivityRepository = $this->entityManager
            ->getRepository(LocalCollectivity::class);
        $list = $localCollectivityRepository->getManagementScalarList($filter, $matchFilters, $orderBys);

        $data = [
                ExportHelper::TAB_NAME => 'contact',
                ExportHelper::HEADERS => [
                    'id',
                    'code',
                    'label',
                    'is_active',
                    'comment',
                    'address_1',
                    'address_2',
                    'postal_code',
                    'password',
                    'has_front_access',
                    'iban',
                    'bic',
                    'territeo_local_collectivity_id',
                    'siren',
                    'observation',
                    'processing',
                    'is_password_to_reset',
                    'last_connection_at',
                    'city',
                ],
                ExportHelper::DATA => $list,
        ];
        $response = new BinaryFileResponse(
            $export->generateXlsFile('export', $data, false)
        );
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        return $response;
    }

    /**
     * For testing purpose, will be removed
     * @Route("/api/back/test_export_multi_tab")
     * @IsGranted("ROLE_BO_LOCAL_COLLECTIVITY_READ")
     */
    public function testExportMultiTab(ExportHelper $export): BinaryFileResponse
    {
        $matchFilters = [
            "id" => ["lc.id"],
            "code" => ["lc.code"],
            "label" => ["lc.label"],
            "is_active" => ["lc.is_active"],
            "comment" => ["lc.comment"],
            "address_1" => ["lc.address_1"],
            "address_2" => ["lc.address_2"],
            "postal_code" => ["lc.postal_code"],
            "password" => ["lc.password"],
            "has_front_access" => ["lc.has_front_access"],
            "iban" => ["lc.iban"],
            "bic" => ["lc.bic"],
            "territeo_local_collectivity_id" => ["lc.territeo_local_collectivity_id"],
            "siren" => ["lc.siren"],
            "observation" => ["lc.observation"],
            "processing" => ["lc.processing"],
            "is_password_to_reset" => ["lc.is_password_to_reset"],
            "last_connection_at" => ["lc.last_connection_at"],
        ];
        [$filter, $orderBys] = $this->extractedFilter();

        /** @var LocalCollectivityRepository $localCollectivityRepository */
        $localCollectivityRepository = $this->entityManager
            ->getRepository(LocalCollectivity::class);
        $list = $localCollectivityRepository->getManagementScalarList($filter, $matchFilters, $orderBys);

        $data = [
            [
                ExportHelper::TAB_NAME => 'contact 1',
                ExportHelper::HEADERS => [
                    'id',
                    'code',
                    'label',
                    'is_active',
                    'comment',
                    'address_1',
                    'address_2',
                    'postal_code',
                    'password',
                    'has_front_access',
                    'iban',
                    'bic',
                    'territeo_local_collectivity_id',
                    'siren',
                    'observation',
                    'processing',
                    'is_password_to_reset',
                    'last_connection_at',
                    'city',
                ],
                ExportHelper::DATA => $list,
            ],
            [
                ExportHelper::TAB_NAME => 'contact 2',
                ExportHelper::HEADERS => [
                    'id',
                    'code',
                    'label',
                    'is_active',
                    'comment',
                    'address_1',
                    'address_2',
                    'postal_code',
                    'password',
                    'has_front_access',
                    'iban',
                    'bic',
                    'territeo_local_collectivity_id',
                    'siren',
                    'observation',
                    'processing',
                    'is_password_to_reset',
                    'last_connection_at',
                    'city',
                ],
                ExportHelper::DATA => $list,
            ],
        ];
        $response = new BinaryFileResponse(
            $export->generateXlsFile('export', $data, true)
        );
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        );

        return $response;
    }
}

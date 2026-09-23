<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-only design/debug tool: launches the Activity in an iframe on this
 * page, pre-authenticated as a chosen player's real JWT — without needing
 * to actually be inside Discord. Useful for design/CSS iteration with hot
 * reload (point ACTIVITY_PREVIEW_URL at `npm run dev`) or for debugging
 * against a deployed build.
 *
 * Protected by nothing *here* — it's the route prefix `/admin` that does
 * it, matching every other admin controller (see security.yaml:
 * `^/admin` requires ROLE_ADMIN via GoogleAuthenticator). There is
 * deliberately no separate bypass reachable from the public Activity
 * itself — the only way to mint one of these preview tokens is to already
 * be a logged-in admin.
 */
#[Route('/admin/activity-preview')]
class ActivityPreviewController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'ACTIVITY_PREVIEW_URL')] private readonly string $activityPreviewUrl,
    ) {
    }

    #[Route('', name: 'admin_activity_preview', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        $users = $userRepository->createQueryBuilder('u')
            ->innerJoin('u.character', 'c')
            ->orderBy('u.displayName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/activity_preview.html.twig', [
            'users' => $users,
            'activity_preview_url' => $this->activityPreviewUrl,
        ]);
    }

    #[Route('/launch/{id}', name: 'admin_activity_preview_launch', methods: ['GET'])]
    public function launch(User $id, JWTTokenManagerInterface $jwtManager): Response
    {
        return $this->render('admin/activity_preview_frame.html.twig', [
            'user' => $id,
            'activityUrl' => rtrim($this->activityPreviewUrl, '/'),
            'token' => $jwtManager->create($id),
        ]);
    }
}

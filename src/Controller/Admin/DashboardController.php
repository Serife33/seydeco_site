<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard/index.html.twig');
    }

    #[Route('/admin/plus', name: 'admin_more', methods: ['GET'])]
    public function more(): Response
    {
        return $this->render('admin/dashboard/more.html.twig');
    }
}

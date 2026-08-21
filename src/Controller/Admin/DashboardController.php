<?php

namespace App\Controller\Admin;

use App\Article\Entity\Article;
use App\Feed\Entity\Feed;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back-office unique de la refonte (§3.12 de la documentation fonctionnelle) : couvre flux,
 * mots-clés (avec l'écran de validation, voir TaxonomyCrudController), utilisateurs, pays,
 * publications et newsletter. Remplace l'AdminController maison et EasyAdmin 1.x de l'ancienne
 * application, ainsi que la dépendance Sonata jamais branchée.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function index(): Response
    {
        $taxonomyRepository = $this->entityManager->getRepository(Taxonomy::class);

        return $this->render('admin/dashboard.html.twig', [
            'pendingSuggestionsCount' => $this->entityManager->getRepository(Taxonomy::class)
                ->count(['status' => TaxonomyStatus::SUGGESTED]),
            'pendingSuggestions' => $taxonomyRepository->findPendingSuggestions(),
            'activeFeedsCount' => $this->entityManager->getRepository(Feed::class)->count(['active' => true]),
            'recentArticlesCount' => $this->entityManager->getRepository(Article::class)->count([]),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Afrique Actualités')
            ->setDefaultColorScheme('auto')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-gauge-high');

        yield MenuItem::section('Contenu');
        yield MenuItem::linkTo(FeedCrudController::class, 'Flux RSS', 'fa fa-rss');
        yield MenuItem::linkTo(ArticleCrudController::class, 'Articles', 'fa fa-newspaper');

        yield MenuItem::section('Mots-clés');
        yield MenuItem::linkTo(TaxonomyCrudController::class, 'Taxonomies', 'fa fa-tags');

        yield MenuItem::section('Géographie');
        yield MenuItem::linkTo(CountryCrudController::class, 'Pays', 'fa fa-earth-africa');

        yield MenuItem::section('"Unes" & publications');
        yield MenuItem::linkTo(UserNewsCrudController::class, '"Unes"', 'fa fa-layer-group');
        yield MenuItem::linkTo(PublicationCrudController::class, 'Publications', 'fa fa-rectangle-ad');

        yield MenuItem::section('Newsletter');
        yield MenuItem::linkTo(WeeklyNewsletterCrudController::class, 'Newsletters hebdomadaires', 'fa fa-envelope-open-text');
        yield MenuItem::linkTo(NewsletterSubscriberCrudController::class, 'Abonnés', 'fa fa-at');

        yield MenuItem::section('Utilisateurs');
        yield MenuItem::linkTo(UserCrudController::class, 'Comptes utilisateurs', 'fa fa-users');
    }
}

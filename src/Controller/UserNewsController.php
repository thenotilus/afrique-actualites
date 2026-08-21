<?php

namespace App\Controller;

use App\Article\Repository\ArticleRepository;
use App\News\Entity\UserNews;
use App\News\Form\UserNewsType;
use App\News\Repository\UserNewsRepository;
use App\Shared\Pagination\PaginatedResult;
use App\Shared\Pagination\QueryPaginator;
use App\Shared\ValueObject\Language;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Unes" thématiques utilisateur (§3.4, prompt de conception parcours 1.G) : annuaire public,
 * page d'une "Une" (liste d'articles filtrée par l'ensemble de ses mots-clés), et gestion par
 * l'utilisateur connecté (créer/éditer/dupliquer/supprimer, "mes Unes"). L'inscription reste
 * facultative pour consulter le site (§12) : seule cette gestion personnalisée requiert un compte.
 */
class UserNewsController extends AbstractController
{
    public function __construct(
        private readonly UserNewsRepository $userNewsRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly QueryPaginator $paginator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/unes', name: 'app_user_news_index')]
    public function index(): Response
    {
        return $this->render('public/user_news_index.html.twig', [
            'userNewsList' => $this->userNewsRepository->findPublic(),
        ]);
    }

    #[Route('/unes/{id}/{slug}', name: 'app_user_news_show', requirements: ['id' => '\d+'], defaults: ['slug' => ''])]
    public function show(Request $request, int $id): Response
    {
        $userNews = $this->userNewsRepository->find($id);
        if (null === $userNews || !$this->canView($userNews)) {
            throw $this->createNotFoundException();
        }

        $language = Language::from($request->getLocale());
        $keywordIds = array_map(static fn ($taxonomy) => $taxonomy->getId(), $userNews->getTaxonomies()->toArray());
        $page = max(1, $request->query->getInt('page', 1));

        if ([] === $keywordIds) {
            // Une "Une" sans mot-clé (cas limite, ex. tous ses mots-clés rejetés/archivés depuis)
            // n'a mécaniquement aucun article à lister — inutile d'interroger la base pour ça.
            $pagination = new PaginatedResult([], $page, QueryPaginator::DEFAULT_LIMIT, 0);
        } else {
            $queryBuilder = $this->articleRepository->byAnyKeyword($this->articleRepository->publishedQueryBuilder($language), $keywordIds);
            $pagination = $this->paginator->paginate($queryBuilder, $page);
        }

        return $this->render('public/user_news_show.html.twig', [
            'userNews' => $userNews,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/mes-unes', name: 'app_my_user_news')]
    #[IsGranted('ROLE_USER')]
    public function mine(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('public/my_user_news.html.twig', [
            'userNewsList' => $this->userNewsRepository->findByUser($user),
        ]);
    }

    #[Route('/mes-unes/nouvelle', name: 'app_user_news_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userNews = new UserNews('', $user);

        return $this->handleForm($request, $userNews, isNew: true);
    }

    #[Route('/mes-unes/{id}/editer', name: 'app_user_news_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, int $id): Response
    {
        $userNews = $this->userNewsRepository->find($id);
        if (null === $userNews || !$this->isOwnerOrAdmin($userNews)) {
            throw $this->createNotFoundException();
        }

        return $this->handleForm($request, $userNews, isNew: false);
    }

    #[Route('/mes-unes/{id}/dupliquer', name: 'app_user_news_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function duplicate(Request $request, int $id): Response
    {
        $userNews = $this->userNewsRepository->find($id);
        if (null === $userNews || !$this->isOwnerOrAdmin($userNews)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('user_news_duplicate_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();
        $copy = new UserNews($userNews->getLabel().' (copie)', $user);
        $copy->setPrivate($userNews->isPrivate());
        foreach ($userNews->getTaxonomies() as $taxonomy) {
            $copy->addTaxonomy($taxonomy);
        }
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        $this->addFlash('success', 'user_news.flash.duplicated');

        return $this->redirectToRoute('app_my_user_news');
    }

    #[Route('/mes-unes/{id}/supprimer', name: 'app_user_news_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, int $id): Response
    {
        $userNews = $this->userNewsRepository->find($id);
        if (null === $userNews || !$this->isOwnerOrAdmin($userNews)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('user_news_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($userNews);
        $this->entityManager->flush();

        $this->addFlash('success', 'user_news.flash.deleted');

        return $this->redirectToRoute('app_my_user_news');
    }

    private function handleForm(Request $request, UserNews $userNews, bool $isNew): Response
    {
        $language = Language::from($request->getLocale());
        $form = $this->createForm(UserNewsType::class, $userNews, ['language' => $language]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // §3.4 : la visibilité privée est réservée aux administrateurs à la création (et à
            // l'édition, par cohérence) — un simple utilisateur ne peut pas rendre sa Une privée,
            // quoi que le formulaire ait transmis.
            if (!$this->isGranted(User::ROLE_ADMIN)) {
                $userNews->setPrivate(false);
            }
            $userNews->touchModifiedAt();

            if ($isNew) {
                $this->entityManager->persist($userNews);
            }
            $this->entityManager->flush();

            $this->addFlash('success', $isNew ? 'user_news.flash.created' : 'user_news.flash.updated');

            return $this->redirectToRoute('app_my_user_news');
        }

        return $this->render('public/user_news_form.html.twig', [
            'form' => $form,
            'isNew' => $isNew,
        ]);
    }

    private function canView(UserNews $userNews): bool
    {
        return !$userNews->isPrivate() || $this->isOwnerOrAdmin($userNews);
    }

    private function isOwnerOrAdmin(UserNews $userNews): bool
    {
        $user = $this->getUser();

        return $user instanceof User && ($user === $userNews->getUser() || $this->isGranted(User::ROLE_ADMIN));
    }
}

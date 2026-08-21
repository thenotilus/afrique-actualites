<?php

namespace App\Controller\Admin;

use App\Shared\ValueObject\Language;
use App\Taxonomy\Entity\Taxonomy;
use App\Taxonomy\Enum\TaxonomyStatus;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'écran le plus important du back-office (§4.4, §10.4, §11.3 de la documentation
 * fonctionnelle) : la file des suggestions de mots-clés détectées automatiquement, qu'un
 * administrateur doit valider ou rejeter avant qu'elles ne deviennent utilisables pour classer
 * et taguer les articles.
 *
 * @extends AbstractCrudController<Taxonomy>
 */
class TaxonomyCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Taxonomy::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Taxonomie')
            ->setEntityLabelInPlural('Taxonomies')
            ->setHelp(
                Crud::PAGE_INDEX,
                'Les suggestions détectées automatiquement doivent être validées avant de devenir '
                .'des mots-clés utilisables pour classer et taguer les articles.',
            )
            // Les suggestions en attente remontent en premier : c'est la file de travail.
            ->setDefaultSort(['status' => 'ASC', 'createdAt' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('label', 'Libellé');
        yield ChoiceField::new('language', 'Langue')
            ->setChoices(array_combine(
                array_map(static fn (Language $language) => $language->label(), Language::cases()),
                Language::cases(),
            ));
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(array_combine(
                array_map(static fn (TaxonomyStatus $status) => $status->label(), TaxonomyStatus::cases()),
                TaxonomyStatus::cases(),
            ))
            ->renderAsBadges(static fn ($field) => match ($field->getValue()) {
                TaxonomyStatus::SUGGESTED => 'warning',
                TaxonomyStatus::VALIDATED => 'success',
                TaxonomyStatus::REJECTED => 'danger',
                TaxonomyStatus::ARCHIVED => 'secondary',
                default => 'secondary',
            })
            ->setFormTypeOption('disabled', true);
        yield IntegerField::new('mark', 'Pondération')
            ->setHelp('Exploitée par le scoring du moteur de classification (phase 4).')
            ->hideOnIndex();
        yield TextField::new('validatedBy.email', 'Traité par')
            ->onlyOnIndex();
        yield DateTimeField::new('validatedAt', 'Traité le')
            ->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Détecté le')
            ->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(
                array_map(static fn (TaxonomyStatus $status) => $status->label(), TaxonomyStatus::cases()),
                TaxonomyStatus::cases(),
            )))
            ->add(ChoiceFilter::new('language', 'Langue')->setChoices(array_combine(
                array_map(static fn (Language $language) => $language->label(), Language::cases()),
                Language::cases(),
            )));
    }

    public function configureActions(Actions $actions): Actions
    {
        $validate = Action::new('validate', 'Valider', 'fa fa-check')
            ->linkToCrudAction('validate')
            ->displayIf(static fn (Taxonomy $taxonomy) => TaxonomyStatus::SUGGESTED === $taxonomy->getStatus())
            ->addCssClass('btn btn-success');

        $reject = Action::new('reject', 'Rejeter', 'fa fa-xmark')
            ->linkToCrudAction('reject')
            ->displayIf(static fn (Taxonomy $taxonomy) => TaxonomyStatus::SUGGESTED === $taxonomy->getStatus())
            ->addCssClass('btn btn-danger');

        $batchValidate = Action::new('batchValidate', 'Valider la sélection', 'fa fa-check')
            ->linkToCrudAction('batchValidate')
            ->addCssClass('btn btn-success');

        $batchReject = Action::new('batchReject', 'Rejeter la sélection', 'fa fa-xmark')
            ->linkToCrudAction('batchReject')
            ->addCssClass('btn btn-danger');

        return $actions
            ->add(Crud::PAGE_INDEX, $validate)
            ->add(Crud::PAGE_INDEX, $reject)
            ->addBatchAction($batchValidate)
            ->addBatchAction($batchReject)
            // Statut géré exclusivement via Valider/Rejeter/Archiver : pas d'édition libre.
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::DELETE);
    }

    #[AdminRoute('/{entityId}/validate', 'validate', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function validate(int $entityId): Response
    {
        $taxonomy = $this->findTaxonomyOrFail($entityId);
        $taxonomy->validate($this->getAdminUser());
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Le mot-clé « %s » a été validé.', $taxonomy->getLabel()));

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setAction(Action::INDEX)->generateUrl());
    }

    #[AdminRoute('/{entityId}/reject', 'reject', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function reject(int $entityId): Response
    {
        $taxonomy = $this->findTaxonomyOrFail($entityId);
        $taxonomy->reject($this->getAdminUser());
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Le mot-clé « %s » a été rejeté.', $taxonomy->getLabel()));

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setAction(Action::INDEX)->generateUrl());
    }

    private function findTaxonomyOrFail(int $entityId): Taxonomy
    {
        $taxonomy = $this->entityManager->getRepository(Taxonomy::class)->find($entityId);
        if (null === $taxonomy) {
            throw $this->createNotFoundException(sprintf('Taxonomie #%d introuvable.', $entityId));
        }

        return $taxonomy;
    }

    #[AdminRoute('/batch-validate', 'batch_validate', options: ['methods' => ['POST']])]
    public function batchValidate(BatchActionDto $batchActionDto): Response
    {
        $admin = $this->getAdminUser();
        $repository = $this->entityManager->getRepository(Taxonomy::class);

        $count = 0;
        foreach ($batchActionDto->getEntityIds() as $entityId) {
            $taxonomy = $repository->find($entityId);
            if (null !== $taxonomy && TaxonomyStatus::SUGGESTED === $taxonomy->getStatus()) {
                $taxonomy->validate($admin);
                ++$count;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%d mot(s)-clé(s) validé(s).', $count));

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setAction(Action::INDEX)->generateUrl());
    }

    #[AdminRoute('/batch-reject', 'batch_reject', options: ['methods' => ['POST']])]
    public function batchReject(BatchActionDto $batchActionDto): Response
    {
        $admin = $this->getAdminUser();
        $repository = $this->entityManager->getRepository(Taxonomy::class);

        $count = 0;
        foreach ($batchActionDto->getEntityIds() as $entityId) {
            $taxonomy = $repository->find($entityId);
            if (null !== $taxonomy && TaxonomyStatus::SUGGESTED === $taxonomy->getStatus()) {
                $taxonomy->reject($admin);
                ++$count;
            }
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%d mot(s)-clé(s) rejeté(s).', $count));

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setAction(Action::INDEX)->generateUrl());
    }

    private function getAdminUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Cette action requiert un administrateur authentifié.');
        }

        return $user;
    }
}

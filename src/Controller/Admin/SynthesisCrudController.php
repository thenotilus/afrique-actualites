<?php

namespace App\Controller\Admin;

use App\Shared\ValueObject\Language;
use App\Synthesis\Entity\Synthesis;
use App\Synthesis\Enum\SynthesisStatus;
use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écran de relecture des synthèses hebdomadaires (§ "Workflow de validation") : chaque synthèse
 * générée par `app:synthesis:generate` arrive au statut brouillon, avec ses articles sources pour
 * traçabilité — un administrateur relit le texte (et peut le corriger via l'action "Modifier"),
 * puis publie ou rejette. Calqué sur `TaxonomyCrudController`, l'écran de validation existant le
 * plus proche dans ce dépôt.
 *
 * @extends AbstractCrudController<Synthesis>
 */
class SynthesisCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Synthesis::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Synthèse hebdomadaire')
            ->setEntityLabelInPlural('Synthèses hebdomadaires')
            ->setHelp(
                Crud::PAGE_INDEX,
                'Les synthèses générées automatiquement chaque semaine arrivent en brouillon : '
                .'relisez le texte et les articles sources avant de publier ou de rejeter.',
            )
            // Les brouillons en attente remontent en premier ; à statut égal, les plus récemment
            // générées d'abord.
            ->setDefaultSort(['status' => 'ASC', 'generatedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('scopeLabel', 'Pays / Région')->hideOnForm();
        yield ChoiceField::new('language', 'Langue')
            ->setChoices(array_combine(
                array_map(static fn (Language $language) => $language->label(), Language::cases()),
                Language::cases(),
            ))
            ->hideOnForm();
        yield DateTimeField::new('weekStart', 'Début de semaine')->setFormat('dd/MM/yyyy')->hideOnForm();
        yield DateTimeField::new('weekEnd', 'Fin de semaine')->setFormat('dd/MM/yyyy')->hideOnForm();
        yield TextField::new('title', 'Titre');
        yield TextareaField::new('lead', 'Chapô');
        yield TextareaField::new('body', 'Corps (HTML)')
            ->hideOnIndex()
            ->setHelp('Sections assemblées automatiquement par le pipeline ; modifiable si besoin avant publication.');
        yield AssociationField::new('sourceArticles', 'Articles sources')
            ->onlyOnDetail()
            ->setHelp('Articles utilisés par le pipeline pour cette synthèse (traçabilité).');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(array_combine(
                array_map(static fn (SynthesisStatus $status) => $status->label(), SynthesisStatus::cases()),
                SynthesisStatus::cases(),
            ))
            ->renderAsBadges([
                SynthesisStatus::DRAFT->value => 'warning',
                SynthesisStatus::PUBLISHED->value => 'success',
                SynthesisStatus::REJECTED->value => 'danger',
            ])
            ->setFormTypeOption('disabled', true);
        yield TextField::new('reviewedBy.email', 'Traité par')->onlyOnIndex();
        yield DateTimeField::new('generatedAt', 'Générée le')->hideOnForm();
        yield DateTimeField::new('publishedAt', 'Publiée le')->onlyOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(
                array_map(static fn (SynthesisStatus $status) => $status->label(), SynthesisStatus::cases()),
                SynthesisStatus::cases(),
            )))
            ->add(ChoiceFilter::new('language', 'Langue')->setChoices(array_combine(
                array_map(static fn (Language $language) => $language->label(), Language::cases()),
                Language::cases(),
            )));
    }

    public function configureActions(Actions $actions): Actions
    {
        // renderAsForm() : ces actions changent le statut, leurs routes n'acceptent donc que POST
        // (même piège documenté sur TaxonomyCrudController : un lien <a> en GET produirait un 405).
        $publish = Action::new('publish', 'Publier', 'fa fa-check')
            ->linkToCrudAction('publish')
            ->renderAsForm()
            ->displayIf(static fn (Synthesis $synthesis) => $synthesis->isDraft())
            ->addCssClass('btn btn-success');

        $reject = Action::new('reject', 'Rejeter', 'fa fa-xmark')
            ->linkToCrudAction('reject')
            ->renderAsForm()
            ->displayIf(static fn (Synthesis $synthesis) => SynthesisStatus::REJECTED !== $synthesis->getStatus())
            ->addCssClass('btn btn-danger');

        return $actions
            ->add(Crud::PAGE_INDEX, $publish)
            ->add(Crud::PAGE_INDEX, $reject)
            ->add(Crud::PAGE_DETAIL, $publish)
            ->add(Crud::PAGE_DETAIL, $reject)
            // Seul le pipeline crée des synthèses ; pas de création manuelle depuis le back-office.
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::DELETE);
    }

    #[AdminRoute('/{entityId}/publish', 'publish', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function publish(int $entityId): Response
    {
        $synthesis = $this->findSynthesisOrFail($entityId);
        $synthesis->publish($this->getAdminUser());
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('La synthèse « %s » a été publiée.', $synthesis->getTitle()));

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setAction(Action::INDEX)->generateUrl());
    }

    #[AdminRoute('/{entityId}/reject', 'reject', options: ['methods' => ['POST'], 'requirements' => ['entityId' => '\d+']])]
    public function reject(int $entityId): Response
    {
        $synthesis = $this->findSynthesisOrFail($entityId);
        $synthesis->reject($this->getAdminUser());
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('La synthèse « %s » a été rejetée.', $synthesis->getTitle()));

        return $this->redirect($this->container->get(AdminUrlGenerator::class)->setAction(Action::INDEX)->generateUrl());
    }

    private function findSynthesisOrFail(int $entityId): Synthesis
    {
        $synthesis = $this->entityManager->getRepository(Synthesis::class)->find($entityId);
        if (null === $synthesis) {
            throw $this->createNotFoundException(sprintf('Synthèse #%d introuvable.', $entityId));
        }

        return $synthesis;
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

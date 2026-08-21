<?php

namespace App\Controller\Admin;

use App\Newsletter\Entity\WeeklyNewsletter;
use App\Taxonomy\Enum\TaxonomyStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Newsletter hebdomadaire (§3.7) : contenu et ciblage édités à la main par un administrateur.
 * La newsletter quotidienne de l'ancienne application n'est pas reprise (abandonnée).
 *
 * Le sélecteur de mots-clés n'offre que des taxonomies VALIDATED (§10.4), pour la même raison
 * que sur l'écran "Une" : la garde de `WeeklyNewsletter::addKeyword()` n'est pas appelée par un
 * champ association standard, donc la restriction se fait au niveau des choix proposés.
 *
 * @extends AbstractCrudController<WeeklyNewsletter>
 */
class WeeklyNewsletterCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return WeeklyNewsletter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Newsletter hebdomadaire')
            ->setEntityLabelInPlural('Newsletters hebdomadaires')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('subject', 'Objet');
        yield TextareaField::new('text', 'Texte principal')->hideOnIndex();
        yield TextareaField::new('additionalText', 'Texte additionnel')->hideOnIndex();
        yield AssociationField::new('keywords', 'Mots-clés inclus')
            ->autocomplete()
            ->setQueryBuilder(static fn (QueryBuilder $qb) => $qb
                ->andWhere('entity.status = :status')
                ->setParameter('status', TaxonomyStatus::VALIDATED));
        yield AssociationField::new('articles', 'Articles inclus')->autocomplete();
        yield BooleanField::new('allSubscribers', 'Tous les abonnés')->renderAsSwitch();
        yield AssociationField::new('targetedSubscribers', 'Abonnés ciblés')
            ->autocomplete()
            ->setHelp('Utilisé uniquement si "Tous les abonnés" est désactivé.');
        yield DateTimeField::new('sentAt', 'Envoyée le')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): WeeklyNewsletter
    {
        return new WeeklyNewsletter('');
    }
}

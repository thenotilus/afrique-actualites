<?php

namespace App\Controller\Admin;

use App\News\Entity\UserNews;
use App\Taxonomy\Enum\TaxonomyStatus;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * "Unes" thématiques (§3.4). Le sélecteur de mots-clés n'offre que des taxonomies VALIDATED
 * (§10.4) : c'est la garantie côté formulaire que le contournement de
 * `UserNews::addTaxonomy()` par l'édition directe de la collection ne peut pas introduire de
 * suggestion non validée dans une "Une".
 *
 * @extends AbstractCrudController<UserNews>
 */
class UserNewsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserNews::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('"Une"')
            ->setEntityLabelInPlural('"Unes"')
            ->setDefaultSort(['label' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('label', 'Nom');
        yield AssociationField::new('user', 'Créée par')->autocomplete();
        yield BooleanField::new('private', 'Privée')->renderAsSwitch();
        yield AssociationField::new('taxonomies', 'Mots-clés')
            ->autocomplete()
            ->setQueryBuilder(static fn (QueryBuilder $qb) => $qb
                ->andWhere('entity.status = :status')
                ->setParameter('status', TaxonomyStatus::VALIDATED));
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
        yield DateTimeField::new('modifiedAt', 'Modifiée le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): UserNews
    {
        return new UserNews('');
    }
}

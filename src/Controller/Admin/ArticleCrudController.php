<?php

namespace App\Controller\Admin;

use App\Article\Entity\Article;
use App\Taxonomy\Entity\Taxonomy;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Gestion des articles agrégés (§3.2). La création manuelle n'est pas exposée ici : un article
 * naît soit de l'extraction d'un flux, soit de la soumission d'une URL par un visiteur (phase 6),
 * jamais d'une saisie libre en back-office.
 *
 * Les taxonomies (sac de mots complet) et les mots-clés validés ne sont pas éditables depuis cet
 * écran : ce sont des données dérivées du pipeline de classification (phase 4), affichées en
 * lecture seule pour ne pas contourner le circuit de validation (§4.4/§10.4) — un champ
 * multi-sélection standard ignorerait la garde `Article::addKeyword()`.
 *
 * @extends AbstractCrudController<Article>
 */
class ArticleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Article')
            ->setEntityLabelInPlural('Articles')
            ->setDefaultSort(['publicationDate' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Titre');
        yield ImageField::new('image', 'Image')->hideOnIndex();
        yield UrlField::new('url', 'URL')->hideOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield AssociationField::new('feed', 'Flux');
        yield DateTimeField::new('publicationDate', 'Date de publication');
        yield BooleanField::new('publish', 'Publié')->renderAsSwitch();
        yield BooleanField::new('shared', 'Partagé')->hideOnForm();
        yield AssociationField::new('keywords', 'Mots-clés validés')
            ->onlyOnIndex()
            ->formatValue(static fn ($value) => implode(', ', array_map(
                static fn (Taxonomy $t) => $t->getLabel(),
                $value instanceof \Traversable ? iterator_to_array($value) : (array) $value,
            )));
        yield AssociationField::new('countries', 'Pays')
            ->setQueryBuilder(static fn (QueryBuilder $qb) => $qb->andWhere('entity.active = true'));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('publish', 'Publié'))
            ->add(EntityFilter::new('feed', 'Flux'))
            ->add(EntityFilter::new('countries', 'Pays'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->remove(Crud::PAGE_INDEX, Action::NEW);
    }
}

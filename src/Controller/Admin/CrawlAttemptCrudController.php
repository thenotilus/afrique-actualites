<?php

namespace App\Controller\Admin;

use App\Crawler\Entity\CrawlAttempt;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Journal d'observabilité du crawling de repli (§9.4) : un enregistrement par requête HTTP
 * effectivement envoyée à un site source. Écran strictement en lecture seule — un journal
 * d'exécution n'a pas vocation à être édité à la main ; voir le résumé par domaine sur le
 * tableau de bord pour une vue agrégée.
 *
 * @extends AbstractCrudController<CrawlAttempt>
 */
class CrawlAttemptCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CrawlAttempt::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tentative de crawl')
            ->setEntityLabelInPlural('Tentatives de crawl')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('domain', 'Domaine');
        yield UrlField::new('url', 'URL')->hideOnIndex();
        yield TextField::new('botProfileName', 'Profil de bot');
        yield BooleanField::new('success', 'Succès');
        yield IntegerField::new('httpStatusCode', 'Code HTTP');
        yield DateTimeField::new('createdAt', 'Tenté le');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('domain', 'Domaine'))
            ->add(BooleanFilter::new('success', 'Succès'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::EDIT)
            ->remove(Crud::PAGE_INDEX, Action::DELETE);
    }
}

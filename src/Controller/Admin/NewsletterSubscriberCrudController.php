<?php

namespace App\Controller\Admin;

use App\Newsletter\Entity\NewsletterSubscriber;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

/**
 * Abonnés à la newsletter hebdomadaire (§3.7) — liste distincte des comptes utilisateurs
 * (UserCrudController), puisque le site ne requiert pas d'inscription pour être consulté.
 *
 * @extends AbstractCrudController<NewsletterSubscriber>
 */
class NewsletterSubscriberCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NewsletterSubscriber::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Abonné')
            ->setEntityLabelInPlural('Abonnés à la newsletter')
            ->setDefaultSort(['subscribedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email', 'Email');
        yield BooleanField::new('active', 'Actif')->renderAsSwitch();
        yield DateTimeField::new('subscribedAt', 'Inscrit le')->hideOnForm();
    }

    public function createEntity(string $entityFqcn): NewsletterSubscriber
    {
        return new NewsletterSubscriber('');
    }
}

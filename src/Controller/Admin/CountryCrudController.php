<?php

namespace App\Controller\Admin;

use App\Geography\Entity\Country;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Référentiel pays (§3.13) : support de la page "actualité par pays". Le rattachement aux
 * articles se gère depuis l'écran Article (relation native Article ↔ Country).
 *
 * @extends AbstractCrudController<Country>
 */
class CountryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Country::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pays')
            ->setEntityLabelInPlural('Pays')
            ->setDefaultSort(['nameFr' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Code');
        yield TextField::new('nameFr', 'Nom (FR)');
        yield TextField::new('nameEn', 'Nom (EN)');
        yield BooleanField::new('active', 'Actif')->renderAsSwitch();
    }

    public function createEntity(string $entityFqcn): Country
    {
        return new Country('', '', '');
    }
}

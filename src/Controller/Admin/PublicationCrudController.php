<?php

namespace App\Controller\Admin;

use App\News\Entity\Publication;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Encarts/bandeaux (§3.6), publicitaires ou éditoriaux, insérés dans les listes d'articles.
 *
 * @extends AbstractCrudController<Publication>
 */
class PublicationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Publication::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Publication')
            ->setEntityLabelInPlural('Publications')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Titre');
        yield TextEditorField::new('content', 'Contenu')->hideOnIndex();
        yield UrlField::new('link', 'Lien')->hideOnIndex();
        yield IntegerField::new('position', 'Position');
        yield BooleanField::new('active', 'Active')->renderAsSwitch();
        yield BooleanField::new('general', 'Générale (toutes les pages)')->renderAsSwitch();
        yield AssociationField::new('newsList', '"Unes" ciblées')
            ->autocomplete()
            ->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): Publication
    {
        return new Publication('', '');
    }
}

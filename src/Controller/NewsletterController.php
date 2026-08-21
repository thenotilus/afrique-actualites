<?php

namespace App\Controller;

use App\Newsletter\Entity\NewsletterSubscriber;
use App\Newsletter\Repository\NewsletterSubscriberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Capture d'email pour la newsletter hebdomadaire (§3.7, prompt de conception parcours 1.I) :
 * cible d'un simple formulaire HTML présent en pied de page sur l'ensemble du site
 * (`public/base.html.twig`), pas un formulaire Symfony Form dédié — un seul champ ne le justifie
 * pas, et la simplicité du balisage prime pour un encart répété sur chaque page.
 */
class NewsletterController extends AbstractController
{
    #[Route('/newsletter/inscription', name: 'app_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        ValidatorInterface $validator,
        NewsletterSubscriberRepository $subscriberRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('newsletter_subscribe', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $email = trim((string) $request->request->get('email', ''));
        $violations = $validator->validate($email, [new NotBlank(), new Email()]);

        if (\count($violations) > 0) {
            $this->addFlash('error', 'newsletter.flash.invalid');

            return $this->redirectToRoute('app_home');
        }

        if (null === $subscriberRepository->findOneBy(['email' => $email])) {
            $entityManager->persist(new NewsletterSubscriber($email));
            $entityManager->flush();
        }

        $this->addFlash('success', 'newsletter.flash.subscribed');

        // Toujours vers l'accueil plutôt que l'en-tête Referer (non fiable, redirection ouverte
        // potentielle) : ce formulaire n'apparaît qu'en pied de page, une redirection précise
        // vers la page d'origine n'apporte pas assez de valeur pour justifier ce risque.
        return $this->redirectToRoute('app_home');
    }
}

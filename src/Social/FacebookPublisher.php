<?php

namespace App\Social;

use App\Article\Entity\Article;
use App\Social\Exception\FacebookPublishException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publication d'un article sur la Page Facebook du média (§3.8, `Social/` — interface d'extension
 * posée en phase 1-2, implémentation branchée ici). Poste sur le point de terminaison Graph
 * `/{page-id}/feed` : `link` porte l'URL de l'article, dont Facebook dérive lui-même l'aperçu
 * (titre/image/description) depuis les balises OpenGraph de la page source — les mêmes que celles
 * lues par {@see \App\Crawler\OpenGraphMetaExtractor} pour le crawl de repli — donc aucune
 * duplication de logique d'extraction ici ; `message` ne porte que le titre en clair.
 *
 * Ne persiste rien : marquer l'article `shared`/`sharedAt` reste à la charge de l'appelant
 * ({@see Command\ShareArticlesCommand}), après confirmation du succès de la publication.
 */
final readonly class FacebookPublisher
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $pageId,
        private string $pageAccessToken,
        private string $graphApiVersion,
        private float $requestTimeout,
    ) {
    }

    /**
     * @return string l'identifiant du post Facebook créé
     *
     * @throws FacebookPublishException     si l'API Graph refuse ou échoue la publication
     * @throws HttpClientExceptionInterface si la requête HTTP elle-même échoue (réseau, timeout)
     */
    public function publish(Article $article): string
    {
        $response = $this->httpClient->request('POST', sprintf('https://graph.facebook.com/%s/%s/feed', $this->graphApiVersion, $this->pageId), [
            'timeout' => $this->requestTimeout,
            'body' => [
                'message' => $article->getTitle(),
                'link' => $article->getUrl(),
                'access_token' => $this->pageAccessToken,
            ],
        ]);

        // `false` : on lit le corps même sur un code d'erreur, l'API Graph y détaillant la cause
        // (`error.message`) plutôt que de se contenter d'un statut HTTP nu.
        $payload = json_decode($response->getContent(false), true, flags: \JSON_BIGINT_AS_STRING);

        if (isset($payload['error'])) {
            throw new FacebookPublishException(sprintf('Erreur API Graph : %s', $payload['error']['message'] ?? 'raison inconnue'));
        }

        if (!isset($payload['id']) || !\is_string($payload['id'])) {
            throw new FacebookPublishException('Réponse de l\'API Graph inexploitable : identifiant de publication absent.');
        }

        return $payload['id'];
    }
}

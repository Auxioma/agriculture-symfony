<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Les actions de ligne d'EasyAdmin (valider, anonymiser, masquer...) sont des liens : un simple GET modifiait des données,
 * sans jeton ni confirmation. Ici l'action devient un petit formulaire POST dont l'adresse porte un jeton CSRF.
 *
 * Côté contrôleur : la route avec `options: ['methods' => ['POST']]`, et assertPostWithToken() en première ligne de la
 * méthode. Le jeton est lié au nom de l'action, pas à la ligne : celui de "Valider" ne sert pas pour "Anonymiser".
 */
trait CsrfPostActionTrait
{
    private const ACTION_TOKEN_PARAM = '_token';

    /**
     * @param string $crudAction nom de la MÉTHODE du contrôleur (celle qui porte #[AdminRoute])
     */
    protected function postAction(string $name, string $label, string $crudAction): Action
    {
        return Action::new($name, $label)
            ->linkToUrl(fn (object $entity): string => $this->postActionUrl($crudAction, $entity))
            ->renderAsForm();
    }

    // * Même adresse que celle d'EasyAdmin pour linkToCrudAction() (filtres, page, tri gardés), plus le jeton dans l'adresse :
    // * ses boutons ne laissent pas ajouter un champ caché, plusieurs gabarits les affichent.
    private function postActionUrl(string $crudAction, object $entity): string
    {
        $token = $this->container->get('security.csrf.token_manager')->getToken($this->actionTokenId($crudAction))->getValue();

        return $this->container->get(AdminUrlGenerator::class)
            ->unsetAllExcept(EA::FILTERS, EA::PAGE, EA::QUERY, EA::SORT)
            ->setAll([
                EA::CRUD_CONTROLLER_FQCN => static::class,
                EA::CRUD_ACTION => $crudAction,
                EA::ENTITY_ID => (string) $entity->getId(),
                self::ACTION_TOKEN_PARAM => $token,
            ])
            ->generateUrl();
    }

    /**
     * À appeler en tête de l'action. La route est déjà limitée à POST, mais on revérifie ici : un oubli dans
     * l'attribut ne doit pas rouvrir la porte au GET.
     *
     * @template TEntity of object
     *
     * @param AdminContext<TEntity> $context
     */
    protected function assertPostWithToken(AdminContext $context): void
    {
        $request = $context->getRequest();
        if (!$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST']);
        }

        $crudAction = (string) $request->attributes->get(EA::CRUD_ACTION);
        if (!$this->isCsrfTokenValid($this->actionTokenId($crudAction), (string) $request->query->get(self::ACTION_TOKEN_PARAM))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }

    // ! setAction(INDEX) + unset('entityId') obligatoires : sans ça, AdminUrlGenerator réutilise les paramètres de la
    // ! requête en cours (action, entityId) et l'adresse générée repointe vers cette même action -- boucle de
    // ! redirection infinie (déjà vécue sur l'anonymisation). Le jeton de l'action est retiré pour la même raison :
    // ! il est dans l'adresse en cours, on ne le recopie pas dans la suivante.
    protected function redirectToIndex(): Response
    {
        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(static::class)
                ->setAction(Action::INDEX)
                ->unset(EA::ENTITY_ID)
                ->unset(self::ACTION_TOKEN_PARAM)
                ->generateUrl()
        );
    }

    private function actionTokenId(string $crudAction): string
    {
        return 'tm_admin_action_'.$crudAction;
    }
}

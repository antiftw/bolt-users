<?php

declare(strict_types=1);

namespace Bolt\UsersExtension\Controller;

use Bolt\Configuration\Config;
use Bolt\Configuration\Content\ContentType;
use Bolt\Controller\Backend\BackendZoneInterface;
use Bolt\Controller\Backend\ContentEditController;
use Bolt\Controller\TwigAwareController;
use Bolt\Entity\Content;
use Bolt\Entity\User;
use Bolt\Enum\Statuses;
use Bolt\Event\Listener\ContentFillListener;
use Bolt\Repository\ContentRepository;
use Bolt\UsersExtension\ExtensionConfigTrait;
use Bolt\UsersExtension\Utils\ExtensionTemplateChooser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FrontendUsersProfileController extends AccessAwareController implements BackendZoneInterface
{
    use ExtensionConfigTrait;

    public function __construct(
                         Config                   $config,
        private readonly ContentRepository        $contentRepository,
        private readonly ContentEditController    $contentEditController,
        private readonly ContentFillListener      $contentFillListener,
        private readonly TwigAwareController      $twigAwareController,
        private readonly ExtensionTemplateChooser $templateChooser,
        private readonly EntityManagerInterface   $em
    ) {
        parent::__construct($config);
    }

    #[Route('/profile', name: 'extension_frontend_user_profile', methods: ['GET'])]
    public function view(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user instanceof User && $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('bolt_user_edit', ['id' => $user->getId()]);
        }

        if ($user !== null /* Ensure there is an active user logged on*/) {
            $contentTypeSlug = $this->findContentTypeSlug();

            /** @var ContentType $contentType */
            $contentType = $this->getBoltConfig()->getContentType($contentTypeSlug);
            $this->applyAllowForGroupsGuard($contentType);

            return $this->twigAwareController->renderSingle($this->getUserRecord($contentType), false);
        } else {
            // If session was invalidated or ended, redirect user as needed when they try to access profile
            // For instance, redirect to login page to prompt re-authentication
            $redirectRoute = $this->getExtension()->getExtConfig('redirect_on_session_null');
            return $this->redirect($redirectRoute);
        }
    }

    #[Route('/profile/edit', name: 'extension_frontend_user_edit', methods: ['GET', 'POST'])]
    public function edit(ContentType $contentType, Request $request): Response
    {
        $user = $this->getUser();

        if ($user !== null /* Ensure there is an active user logged on*/) {

            $this->applyIsAuthenticatedGuard();

            $contentTypeSlug = $this->findContentTypeSlug();

            /** @var ContentType $contentType */
            $contentType = $this->getBoltConfig()->getContentType($contentTypeSlug);
            $this->applyAllowForGroupsGuard($contentType);
            $this->applyEditProfileGuard();

            $content = $this->getUserRecord($contentType);

            if ($request->getMethod() === 'POST') {
                $this->contentEditController->save($content);
                return $this->redirectToRoute('extension_frontend_user_profile');
            }

            $templates = $this->templateChooser->forProfileEdit($this->findGrantedUserRole());

            $parameters = [
                'record' => $content,
                $content->getContentTypeSingularSlug() => $content,
            ];

            return $this->twigAwareController->render($templates, $parameters);
        } else {
            // If session was invalidated or ended, redirect user as needed when they try to access profile
            // For instance, redirect to login page to prompt re-authentication
            $redirectRoute = $this->getExtension()->getExtConfig('redirect_on_session_null');
            return $this->redirect($redirectRoute);
        }
    }

    private function getUserRecord(ContentType $contentType): Content
    {
        /** @var User $user */
        $user = $this->getUser();

        // Access user record, if available
        $contentTypeSlug = $this->findContentTypeSlug();
        // todo: not sure if the ->first should be here
        $contentType = $this->getBoltConfig()->getContentType($contentTypeSlug)->first();

        $content = $this->contentRepository->findBy([
            'author' => $user,
            'contentType' => $contentType->getSlug(),
        ]);

        // If user record unavailable, create it
        if (empty($content)) {
            return $this->new($contentType->first());
        } elseif (is_iterable($content)) {
            $content = end($content);
        }

        return $content;
    }

    // Define the user record content
    private function new(ContentType $contentType): Content
    {
        /** @var User $user */
        $user = $this->getUser();
        $contentTypeSlug = $this->findContentTypeSlug();
        $contentType = $this->getBoltConfig()->getContentType($contentTypeSlug);

        $content = new Content($contentType);
        $content->setAuthor($user);
        $content->setCreatedAt(new \DateTime());
        $content->setPublishedAt(new \DateTime());
        $content->setStatus(Statuses::PUBLISHED);
        $contentTypeName = strtolower($contentType->get('name', $contentType->get('slug')));
        $content->setContentType($contentTypeName);
        $content->setFieldValue('displayName', $user->getDisplayName()); // Hidden field for record title
        $content->setFieldValue('username', $user->getUsername()); // Hidden field with copy of username
        $content->setFieldValue('slug', $user->getUsername()); // Make slugs unique to users

        // Initialise ALL extra fields as defined in the contenttype with empty strings.
        // This ensures they are displayed on the /profile/edit route without backend intervention
        foreach ($contentType->get('fields') as $name => $field) {

            if (!in_array($name, ['displayName', 'username', 'slug'])) {
                $content->setFieldValue($name, '');
            }
        }

        $this->contentFillListener->fillContent($content);

        // Persist in DB
        $this->saveContent($content);
        return $content;
    }

    // Saves user record to the database
    private function saveContent(Content $content): void
    {
        $this->em->persist($content);
        $this->em->flush();
    }

    private function findContentTypeSlug(): ?string
    {
        $role = $this->findGrantedUserRole();

        $contentTypeSlug = $this->getExtension()->getExtConfig('contenttype', $role);

        if ($contentTypeSlug === null)
            throw new \Exception("Must define a contenttype.");

        return $contentTypeSlug;
    }

}

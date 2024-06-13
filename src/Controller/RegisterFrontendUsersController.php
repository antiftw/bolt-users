<?php

declare(strict_types=1);

namespace Bolt\UsersExtension\Controller;

use Bolt\Configuration\Config;
use Bolt\Controller\CsrfTrait;
use Bolt\Entity\User;
use Bolt\Extension\ExtensionController;
use Bolt\Repository\UserRepository;
use Bolt\UsersExtension\Enum\UserStatus;
use Bolt\UsersExtension\ExtensionConfigInterface;
use Bolt\UsersExtension\ExtensionConfigTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegisterFrontendUsersController extends ExtensionController implements ExtensionConfigInterface
{
    use ExtensionConfigTrait;
    use CsrfTrait;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $passwordEncoder;
    private array $forbiddenRoles = ['ROLE_ADMIN', 'ROLE_EDITOR'];
    private ?Request $request;

    public function __construct(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordEncoder,
        CsrfTokenManagerInterface $csrfTokenManager,
        Config $config,
        RequestStack $requestStack)
    {
        parent::__construct($config);

        $this->em = $em;
        $this->passwordEncoder = $passwordEncoder;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->request = $requestStack->getCurrentRequest();
    }

    #[Route('/register', name: 'extension_edit_frontend_user', methods: ['POST'])]
    public function save(?User $user, ValidatorInterface $validator): Response
    {
        $referer = $this->request->headers->get('referer');

        try {
            $this->validateCsrf('edit_frontend_user');
        } catch (InvalidCsrfTokenException $e) {
            /* @phpstan-ignore-next-line */
            $this->request->getSession()->getFlashBag()->add('error', 'Invalid CSRF token');

            return $this->redirect($referer);
        }

        $user = UserRepository::factory($this->request->get('displayname'), $this->request->get('username'), $this->request->get('email'));

        $user->setDisplayName($this->request->get('displayname', $user->getUsername()));

        $role = $this->request->get('group');
        if (in_array($role, $this->forbiddenRoles, true)) {
            $role = 'ROLE_USER';
        }

        $plainPassword = $this->request->get('password');
        $user->setPlainPassword($plainPassword);
        $user->setRoles([$role]);

        $activationType = $this->getExtension()->getExtConfig('initial_status', $role, UserStatus::ADMIN_CONFIRMATION);

        if (! UserStatus::isValid($activationType)) {
            $this->addFlash('danger', sprintf('Incorrect user initial status (%s)', $activationType));

            return $this->redirect($referer);
        }

        $user->setStatus($activationType);

        $errors = $validator->validate($user);
        if ($errors->count() > 0) {
            /** @var ConstraintViolationInterface $error */
            foreach ($errors as $error) {
                $this->addFlash('danger', $error->getMessage());
            }

            return $this->redirect($referer);
        }

        // Once validated, encode the password
        if ($user->getPlainPassword()) {
            $user->setPassword($this->passwordEncoder->hashPassword($user, $user->getPlainPassword()));
            $user->eraseCredentials();
        }

        $this->em->persist($user);
        $this->em->flush();

        $this->addFlash('success', 'user.updated_profile');

        $routeOrUrl = (string) $this->getExtension()->getExtConfig('redirect_on_register', $role, 'homepage');

        if ($this->isRoute($routeOrUrl)) {
            return $this->redirectToRoute($routeOrUrl);
        }

        return $this->redirect($routeOrUrl);
    }

    private function isRoute(string $route, array $params = []): bool
    {
        try {
            $this->generateUrl($route, $params);
        } catch (RouteNotFoundException $e) {
            return false;
        }

        return true;
    }
}

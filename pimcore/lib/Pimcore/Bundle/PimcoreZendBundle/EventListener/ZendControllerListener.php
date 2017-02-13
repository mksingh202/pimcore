<?php

namespace Pimcore\Bundle\PimcoreZendBundle\EventListener;

use Pimcore\Bundle\PimcoreBundle\Service\Request\TemplateVarsResolver;
use Pimcore\Bundle\PimcoreZendBundle\Controller\ZendControllerInterface;
use Pimcore\Bundle\PimcoreZendBundle\Templating\ZendTemplateReference;
use Sensio\Bundle\FrameworkExtraBundle\Templating\TemplateGuesser;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zend\View\Model\ModelInterface;
use Zend\View\Model\ViewModel;

class ZendControllerListener implements EventSubscriberInterface
{
    /**
     * @var EngineInterface
     */
    protected $templating;

    /**
     * @var TemplateVarsResolver
     */
    protected $varsResolver;

    /**
     * @var TemplateGuesser
     */
    protected $templateGuesser;

    /**
     * @param EngineInterface $templating
     * @param TemplateVarsResolver $varsResolver
     * @param TemplateGuesser $templateGuesser
     */
    public function __construct(EngineInterface $templating, TemplateVarsResolver $varsResolver, TemplateGuesser $templateGuesser)
    {
        $this->templating      = $templating;
        $this->varsResolver    = $varsResolver;
        $this->templateGuesser = $templateGuesser;
    }

    /**
     * Injects a ViewModel instance into the controller and calls preDispatch()
     *
     * @param FilterControllerEvent $event
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        $callable = $event->getController();

        if (!is_array($callable)) {
            return;
        }

        $request    = $event->getRequest();
        $controller = $callable[0];

        if ($controller instanceof ZendControllerInterface) {
            $viewModel = $this->generateViewModel($event);
            $controller->setView($viewModel);

            $request->attributes->set('_zend_controller', $controller);
            $controller->preDispatch($event);
        }
    }

    /**
     * Renders view from ViewModel or array result. This is only called if no Response is returned by the controller.
     *
     * @param GetResponseForControllerResultEvent $event
     */
    public function onKernelView(GetResponseForControllerResultEvent $event)
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_zend_controller');

        if (!$controller || !($controller instanceof ZendControllerInterface)) {
            return;
        }

        // document, editmode
        $standardVars = $this->varsResolver->getTemplateVars();

        $view   = $renderView = $controller->getView();
        $result = $event->getControllerResult();

        if ($result instanceof ModelInterface) {
            // controller can return a completely new ViewModel
            $renderView = $result;
        } else {
            // apply returned variable array to view
            if (is_array($result)) {
                $view->setVariables($result);
            }

            if (null !== $layout = $controller->getLayout()) {
                $view->setVariables($standardVars);
                $renderView = $layout;
            }
        }

        $renderView->setVariables($standardVars);

        $event->setResponse($this->templating->renderResponse(
            $renderView->getTemplate(), [
                '_view' => $renderView
            ]
        ));
    }

    /**
     * Calls postDispatch()
     *
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request    = $event->getRequest();
        $controller = $request->attributes->get('_zend_controller');

        if (!$controller || !($controller instanceof ZendControllerInterface)) {
            return;
        }

        $controller->postDispatch($event);
    }

    /**
     * @param FilterControllerEvent $event
     * @return ViewModel
     */
    protected function generateViewModel(FilterControllerEvent $event)
    {
        $attributeTemplate = $event->getRequest()->attributes->get('contentTemplate');

        $template = null;
        if ($attributeTemplate) {
            $template = $attributeTemplate;
        } else {
            $template = $this->guessTemplateName($event);
        }

        $vars = $this->varsResolver->getTemplateVars($event->getRequest());

        $viewModel = new ViewModel($vars);
        $viewModel->setTemplate($template);

        return $viewModel;
    }

    /**
     * @param FilterControllerEvent $event
     * @return ZendTemplateReference
     */
    protected function guessTemplateName(FilterControllerEvent $event)
    {
        // template guesser uses the name.<format>.<engine> syntax (foo.html.twig) which differs from zend naming (foo.phtml)
        $template = $this->templateGuesser->guessTemplateName(
            $event->getController(),
            $event->getRequest(),
            'zend'
        );

        // transform to a zend engine template
        return new ZendTemplateReference($template->get('bundle'), $template->get('controller'), $template->get('name'));
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
            KernelEvents::VIEW       => 'onKernelView',
            KernelEvents::RESPONSE   => 'onKernelResponse'
        ];
    }
}
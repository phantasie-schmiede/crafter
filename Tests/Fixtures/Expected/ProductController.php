<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Demo Shop.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\DemoShop\Controller;

use PSBits\DemoShop\Domain\Model\Product;
use PSBits\DemoShop\Domain\Repository\ProductRepository;
use PSBits\Foundation\Attribute\PluginAction;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Exception\IllegalObjectTypeException;
use TYPO3\CMS\Extbase\Persistence\Exception\UnknownObjectException;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Class ProductController
 *
 * @package PSBits\DemoShop\Controller
 */
class ProductController extends ActionController
{
    public function __construct(
        protected readonly PersistenceManager $persistenceManager,
        protected readonly ProductRepository  $productRepository,
    ) {
    }

    /**
     * @throws IllegalObjectTypeException
     */
    #[PluginAction(uncached: true)]
    public function createAction(Product $product): ResponseInterface
    {
        $this->productRepository->add($product);
        $this->persistenceManager->persistAll();

        return $this->redirect(actionName: 'show', arguments: ['product' => $product]);
    }

    /**
     * @throws IllegalObjectTypeException
     */
    #[PluginAction(uncached: true)]
    public function deleteAction(Product $product): ResponseInterface
    {
        $this->productRepository->remove($product);

        return $this->redirect('list');
    }

    #[PluginAction(uncached: true)]
    public function editAction(Product $product): ResponseInterface
    {
        $this->view->assign('product', $product);

        return $this->htmlResponse();
    }

    #[PluginAction(default: true)]
    public function listAction(): ResponseInterface
    {
        $this->view->assign('products', $this->productRepository->findAll());

        return $this->htmlResponse();
    }

    #[PluginAction(uncached: true)]
    public function newAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

    #[PluginAction(uncached: true)]
    public function showAction(Product $product): ResponseInterface
    {
        $this->view->assign('product', $product);

        return $this->htmlResponse();
    }

    /**
     * @throws IllegalObjectTypeException
     * @throws UnknownObjectException
     */
    #[PluginAction(uncached: true)]
    public function updateAction(Product $product): ResponseInterface
    {
        $this->productRepository->update($product);
        $this->persistenceManager->persistAll();

        return $this->redirect(actionName: 'show', arguments: ['product' => $product]);
    }
}

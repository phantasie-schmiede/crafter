<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Demo Shop.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\DemoShop\Controller\Backend;

use PSBits\DemoShop\Domain\Repository\ProductRepository;
use PSBits\Foundation\Attribute\ModuleAction;
use PSBits\Foundation\Controller\Backend\AbstractModuleController;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

/**
 * Class ImportController
 *
 * @package PSBits\DemoShop\Controller\Backend
 */
#[AsController]
class ImportController extends AbstractModuleController
{
    public function __construct(
        protected readonly ProductRepository $productRepository,
        ModuleTemplateFactory                $moduleTemplateFactory,
    ) {
        parent::__construct($moduleTemplateFactory);
    }

    #[ModuleAction(default: true)]
    public function indexAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }

    #[ModuleAction]
    public function runAction(): ResponseInterface
    {
        return $this->htmlResponse();
    }
}

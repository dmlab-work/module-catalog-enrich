<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Controller\Adminhtml\Rule;

use DmLab\CatalogEnrich\Model\ResourceModel\Rule as RuleResource;
use DmLab\CatalogEnrich\Model\Rule;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Backend action context with a recorded redirect and messages.
 */
abstract class ControllerTestCase extends TestCase
{
    /**
     * @var array{0: string, 1: array}|null
     */
    protected ?array $redirectedTo = null;

    /**
     * @var string[]
     */
    protected array $errors = [];

    /**
     * @var string[]
     */
    protected array $successes = [];

    /**
     * @var string[]
     */
    protected array $warnings = [];

    /**
     * @var Http&MockObject
     */
    protected Http $request;

    /**
     * @var ResultFactory&MockObject
     */
    protected ResultFactory $resultFactory;

    protected function setUp(): void
    {
        $this->request = $this->createStub(Http::class);
        $this->resultFactory = $this->createStub(ResultFactory::class);
    }

    protected function context(): Context
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function (string $path, array $params = []) use ($redirect): Redirect {
                $this->redirectedTo = [$path, $params];
                return $redirect;
            }
        );
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->errors[] = (string)$m;
            return $messages;
        });
        $messages->method('addSuccessMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->successes[] = (string)$m;
            return $messages;
        });
        $messages->method('addWarningMessage')->willReturnCallback(function ($m) use ($messages) {
            $this->warnings[] = (string)$m;
            return $messages;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultFactory')->willReturn($this->resultFactory);

        return $context;
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function rule(array $data = []): Rule
    {
        return new Rule(
            $this->createStub(ModelContext::class),
            $this->createStub(Registry::class),
            new RuleResource($this->createStub(DbContext::class)),
            null,
            $data
        );
    }
}

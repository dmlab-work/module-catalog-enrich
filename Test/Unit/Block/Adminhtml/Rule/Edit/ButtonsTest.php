<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit\Block\Adminhtml\Rule\Edit;

use DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit\BackButton;
use DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit\DeleteButton;
use DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit\GenericButton;
use DmLab\CatalogEnrich\Block\Adminhtml\Rule\Edit\SaveButton;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class ButtonsTest extends TestCase
{
    /**
     * @var UrlInterface
     */
    private UrlInterface $url;

    protected function setUp(): void
    {
        $this->url = $this->createStub(UrlInterface::class);
        $this->url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/admin/' . $route
                . ($params ? '?' . http_build_query($params) : '')
        );
    }

    private function request(mixed $id): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['id', null, $id]]);

        return $request;
    }

    public function testRuleIdFromRequest(): void
    {
        self::assertSame(5, (new GenericButton($this->url, $this->request('5')))->getRuleId());
        self::assertNull((new GenericButton($this->url, $this->request(null)))->getRuleId());
        self::assertNull((new GenericButton($this->url, $this->request('abc')))->getRuleId());
    }

    public function testBackButtonGoesToGrid(): void
    {
        $data = (new BackButton($this->url, $this->request(null)))->getButtonData();

        self::assertSame('Back', (string)$data['label']);
        self::assertSame("location.href = '/admin/*/*/';", $data['on_click']);
    }

    public function testDeleteButtonPostsToDeleteWithId(): void
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($s): string => (string)$s);
        $escaper->method('escapeJs')->willReturnCallback(static fn ($s): string => addslashes((string)$s));
        $button = new DeleteButton($this->url, $this->request('7'), $escaper);
        $data = $button->getButtonData();

        self::assertSame('/admin/*/*/delete?id=7', $button->getDeleteUrl());
        self::assertSame('Delete Rule', (string)$data['label']);
        self::assertSame(
            "deleteConfirm('Are you sure you want to delete this rule?', '/admin/*/*/delete?id=7', {\"data\": {}})",
            $data['on_click']
        );
    }

    public function testDeleteButtonHiddenOnNewRule(): void
    {
        $button = new DeleteButton($this->url, $this->request(null), $this->createStub(Escaper::class));

        self::assertSame([], $button->getButtonData());
    }

    public function testSaveButtonSubmitsForm(): void
    {
        $data = (new SaveButton($this->url, $this->request(null)))->getButtonData();

        self::assertSame('Save Rule', (string)$data['label']);
        self::assertSame('save', $data['data_attribute']['form-role']);
        self::assertSame(['button' => ['event' => 'save']], $data['data_attribute']['mage-init']);
    }
}

<?php

namespace App\Services\ExternalPayment;

/**
 * 外部支付凭证验证器接口
 * 
 * 用于验证来自第三方支付 SDK（如 Apple IAP、Google Play）的支付凭证
 */
interface ExternalPaymentVerifierInterface
{
    /**
     * 验证支付凭证
     * 
     * @param string $transactionId 交易ID
     * @param string|null $receipt 支付凭证原文
     * @param array $metadata 额外元数据
     * @return array 验证结果
     * @throws \Exception 验证失败时抛出异常
     * 
     * 返回格式:
     * [
     *   'valid' => true,
     *   'transaction_id' => 'xxx',
     *   'amount' => 1000,  // 实际支付金额(分)
     *   'currency' => 'USD',
     *   'purchase_date' => 1712534400,
     *   'product_id' => 'com.example.plan.monthly',
     *   'metadata' => []  // 额外信息
     * ]
     */
    public function verify(string $transactionId, ?string $receipt = null, array $metadata = []): array;

    /**
     * 获取验证器名称
     */
    public function getName(): string;
}

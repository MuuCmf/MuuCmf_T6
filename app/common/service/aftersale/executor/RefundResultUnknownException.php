<?php

namespace app\common\service\aftersale\executor;

/**
 * 原路退回「结果未知」异常
 *
 * 网关调用已发出但没有拿到明确结果（超时、网络中断、响应解析失败等），
 * 资金可能已经打出。调用方不得改走余额或其他退款方式，否则会造成重复打款；
 * 退款单保留「打款中」状态，交由对账/人工处理。
 */
class RefundResultUnknownException extends \Exception
{
}

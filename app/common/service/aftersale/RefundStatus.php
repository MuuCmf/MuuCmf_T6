<?php

namespace app\common\service\aftersale;

/**
 * 售后单状态
 *
 * 直接退款（无审核）由执行器预建为「打款中」，完成后置「已完成」；
 * 审核类状态预留给阶段 4 用户申请流程。
 */
class RefundStatus
{
    /** 待审核 */
    const WAIT_AUDIT = 0;

    /** 已同意 */
    const AGREED = 1;

    /** 已拒绝 */
    const REJECTED = 2;

    /** 打款中（预建单） */
    const PROCESSING = 3;

    /** 已完成 */
    const COMPLETED = 4;

    /** 已关闭（打款失败/人工关闭） */
    const CLOSED = 5;
}

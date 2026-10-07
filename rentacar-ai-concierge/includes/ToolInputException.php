<?php
namespace RCAC;

/**
 * ツールに渡された値が不正なとき。メッセージはそのまま Claude に返して、言い直しを促す。
 */
final class ToolInputException extends \RuntimeException {
}

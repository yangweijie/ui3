<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Canvas;

use Yangweijie\Ui3\Element;

/** A laid-out drawable node (one per Element, with computed geometry). */
final class Node
{
    public function __construct(
        public readonly Element $el,
        public readonly string $type,
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
    ) {
    }
}

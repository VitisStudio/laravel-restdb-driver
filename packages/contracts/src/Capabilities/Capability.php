<?php

declare(strict_types=1);

namespace Vitis\RestDB\Capabilities;

enum Capability: string
{
    case Select = 'select';
    case Columns = 'select.columns';
    case Include = 'select.include';
    case Filter = 'filter';
    case FilterNested = 'filter.nested';
    case FilterOr = 'filter.or';
    case Sort = 'sort';
    case MultiSort = 'sort.multi';
    case Limit = 'page.limit';
    case Offset = 'page.offset';
    case PageNumber = 'page.number';
    case Cursor = 'page.cursor';
    case TotalCount = 'page.total';
    case Insert = 'write.insert';
    case Update = 'write.update';
    case Delete = 'write.delete';
    case ClientIds = 'write.client-ids';
    case Count = 'aggregate.count';
    case Exists = 'aggregate.exists';
}

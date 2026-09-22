<?php

use App\Domain\Ledger\Journal;
use App\Domain\Ledger\JournalLine;

test('balanced journals are accepted', function () {
    $journal = new Journal('XAF', [new JournalLine('cash', 1000, 0), new JournalLine('payable', 0, 1000)]);
    expect($journal->lines)->toHaveCount(2);
});

test('unbalanced journals are rejected', function () {
    new Journal('XAF', [new JournalLine('cash', 1000, 0), new JournalLine('payable', 0, 900)]);
})->throws(DomainException::class);

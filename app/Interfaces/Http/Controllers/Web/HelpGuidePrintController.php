<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use App\Application\Help\HelpGuides;
use Illuminate\View\View;

/** S11: printable / "Save as PDF" version of one user guide (locale from ?lang= via SetPublicLocale). */
final class HelpGuidePrintController
{
    public function __invoke(string $guide): View
    {
        abort_unless(HelpGuides::exists($guide), 404);

        return view('help.print', ['guideKey' => $guide, 'guide' => HelpGuides::load($guide)]);
    }
}

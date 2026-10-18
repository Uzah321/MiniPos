<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CurrentTerminalIsolationContractTest extends TestCase
{
    public function test_terminal_and_printer_mutations_are_derived_from_the_bound_till(): void
    {
        $source = file_get_contents(base_path(
            'Modules/GeneralSettings/Http/Controllers/CurrentTerminalController.php'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString("app()->bound('till') ? app('till') : null", $source);
        $this->assertStringContainsString("'device_label' => ['sometimes', 'nullable', 'string', 'max:255']", $source);
        $this->assertStringContainsString('$printer->company_id = $till->company_id', $source);
        $this->assertStringContainsString('$printer->branch_id = $till->branch_id', $source);
        $this->assertStringContainsString('$printer->till_id = $till->id', $source);
        $this->assertStringContainsString("->where('company_id', \$till->company_id)", $source);
        $this->assertStringContainsString("->where('branch_id', \$till->branch_id)", $source);
        $this->assertStringContainsString("->where('till_id', \$till->id)", $source);

        $this->assertStringNotContainsString("\$request->input('company_id')", $source);
        $this->assertStringNotContainsString("\$request->input('branch_id')", $source);
        $this->assertStringNotContainsString("\$request->input('till_id')", $source);
    }

    public function test_front_office_printer_resolution_ignores_asserted_scope_ids(): void
    {
        $source = file_get_contents(base_path(
            'Modules/GeneralSettings/Http/Controllers/PrinterController.php'
        ));
        $method = substr($source, strpos($source, 'public function resolveForPrinting'));

        $this->assertStringContainsString('$branchId = $till->branch_id', $method);
        $this->assertStringContainsString('$tillId = $till->id', $method);
        $this->assertStringNotContainsString('$request->branch_id', $method);
        $this->assertStringNotContainsString('$request->till_id', $method);
        $this->assertStringNotContainsString("header('X-Till-Id')", $method);
    }
}

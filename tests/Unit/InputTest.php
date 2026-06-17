<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Input;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    private function input(array|object|null $body): Input
    {
        $request = (new Psr17Factory)->createServerRequest('POST', '/')->withParsedBody($body);

        return Input::fromRequest($request);
    }

    public function testStringReadsAField(): void
    {
        $this->assertSame('Ada', $this->input(['name' => 'Ada'])->string('name'));
    }

    public function testStringFallsBackToDefaultWhenAbsent(): void
    {
        $input = $this->input(['name' => 'Ada']);

        $this->assertSame('', $input->string('missing'));
        $this->assertSame('anon', $input->string('missing', 'anon'));
    }

    public function testStringDoesNotTrim(): void
    {
        // Trimming is the caller's choice; the reader returns the value as sent.
        $this->assertSame('  spaced  ', $this->input(['x' => '  spaced  '])->string('x'));
    }

    public function testStringKeepsFalsyZero(): void
    {
        $this->assertSame('0', $this->input(['x' => '0'])->string('x'));
    }

    public function testStringDefaultsWhenFieldIsAnArray(): void
    {
        // name[] arrives as an array; stringifying it would be a TypeError.
        $this->assertSame('', $this->input(['name' => ['a', 'b']])->string('name'));
    }

    public function testIntCoercesNumericStrings(): void
    {
        $this->assertSame(42, $this->input(['age' => '42'])->int('age'));
        $this->assertSame(0, $this->input(['age' => '0'])->int('age'));
    }

    public function testIntDefaultsOnNonNumeric(): void
    {
        $this->assertNull($this->input(['age' => 'old'])->int('age'));
        $this->assertSame(-1, $this->input([])->int('age', -1));
        $this->assertNull($this->input(['age' => ''])->int('age'));
    }

    public function testHasDistinguishesPresentEmptyFromAbsent(): void
    {
        $input = $this->input(['name' => '']);

        $this->assertTrue($input->has('name'));
        $this->assertFalse($input->has('missing'));
    }

    public function testHandlesNullParsedBody(): void
    {
        $input = $this->input(null);

        $this->assertFalse($input->has('name'));
        $this->assertSame('', $input->string('name'));
    }

    public function testHandlesObjectParsedBody(): void
    {
        // PSR-7 getParsedBody() may return an object; (array) casts public
        // props to keys, so a stdClass body reads back by field name.
        $input = $this->input((object) ['name' => 'Ada', 'age' => '42']);

        $this->assertTrue($input->has('name'));
        $this->assertSame('Ada', $input->string('name'));
        $this->assertSame(42, $input->int('age'));
    }
}

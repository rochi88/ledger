<?php /** @noinspection PhpParamsInspection */

namespace Tests\Feature;

use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerBalance;
use App\Models\LedgerDomain;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test Ledger API calls that don't involve journal transactions.
 */
class JournalEntryTest extends TestCase
{
    use CommonChecks;
    use CreateLedgerTrait;
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        self::$expectContent = 'entry';
    }

    protected function addAccount(string $code, string $parentCode)
    {
        // Add an account
        $requestData = [
            'code' => $code,
            'parent' => [
                'code' => $parentCode,
            ],
            'names' => [
                [
                    'name' => "Account $code with parent $parentCode",
                    'language' => 'en',
                ]
            ]
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/add', $requestData
        );

        return $this->isSuccessful($response, 'account');
    }

    protected function addSalesTransaction() {
        // Add a transaction, sales to A/R
        $requestData = [
            'currency' => 'CAD',
            'description' => 'Sold the first thing!',
            'date' => '2021-11-12',
            'details' => [
                [
                    'accountCode' => '1310',
                    'debit' => '520.00'
                ],
                [
                    'accountCode' => '4110',
                    'credit' => '520.00'
                ],
            ]
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/entry/add', $requestData
        );

        return [$requestData, $response];
    }

    protected function addSplitTransaction() {
        // Add a transaction, sales to A/R
        $requestData = [
            'currency' => 'CAD',
            'description' => 'Got paid for the first thing!',
            'date' => '2021-11-12',
            'details' => [
                [
                    'accountCode' => '4110',
                    'amount' => '-520.00'
                ],
                [
                    'accountCode' => '1120',
                    'amount' => '500.00'
                ],
                [
                    'accountCode' => '2250',
                    'amount' => '20.00'
                ],
            ]
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/entry/add', $requestData
        );

        return [$requestData, $response];
    }

    /**
     * Create a valid ledger
     *
     * @return void
     * @throws \Exception
     */
    public function testCreate(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );
        $response = $this->createLedger(['template'], ['template' => 'common']);

        $this->isSuccessful($response, 'ledger');

        LedgerAccount::loadRoot();
        //print_r(LedgerAccount::root());
        foreach (LedgerAccount::all() as $item) {
            echo "$item->ledgerUuid $item->code ($item->parentUuid) ";
            echo $item->category ? 'cat ' : '    ';
            if ($item->debit) echo 'DR __';
            if ($item->credit) echo '__ CR';
            echo "\n";
            foreach ($item->names as $name) {
                echo "$name->name $name->language\n";
            }
        }
    }

    public function testAdd()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger
        $response = $this->createLedger(['template'], ['template' => 'common']);

        $this->isSuccessful($response, 'ledger');

        [$requestData, $response] = $this->addSalesTransaction();
        $actual = $this->isSuccessful($response);
        $this->hasRevisionElements($actual->entry);

        // Check that we really did do everything that was supposed to be done.
        $journalEntry = JournalEntry::find($actual->entry->id);
        $this->assertNotNull($journalEntry);
        $this->assertTrue(
            $journalEntry->transDate->equalTo(new Carbon($requestData['date']))
        );
        $this->assertEquals('CAD', $journalEntry->currency);
        $this->assertEquals($requestData['description'], $journalEntry->description);
        $ledgerDomain = LedgerDomain::find($journalEntry->domainUuid);
        $this->assertNotNull($ledgerDomain);
        $this->assertEquals('Corp', $ledgerDomain->code);

        /** @var JournalDetail $detail */
        foreach ($journalEntry->details as $detail) {
            $ledgerAccount = LedgerAccount::find($detail->ledgerUuid);
            $this->assertNotNull($ledgerAccount);
            $ledgerBalance = LedgerBalance::where([
                    ['ledgerUuid', '=', $detail->ledgerUuid],
                    ['domainUuid', '=', $ledgerDomain->domainUuid],
                    ['currency', '=', $journalEntry->currency]]
            )->first();
            $this->assertNotNull($ledgerBalance);
            if ($ledgerAccount->code === '1310') {
                $this->assertEquals('-520.00', $ledgerBalance->balance);
            } else {
                $this->assertEquals('520.00', $ledgerBalance->balance);
            }
        }
    }

    public function testAddSplit()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger
        $response = $this->createLedger(['template'], ['template' => 'common']);

        $this->isSuccessful($response, 'ledger');

        $this->addSalesTransaction();
        [$requestData, $response] = $this->addSplitTransaction();
        $actual = $this->isSuccessful($response);
        $this->hasRevisionElements($actual->entry);

        // Check that we really did do everything that was supposed to be done.
        $journalEntry = JournalEntry::find($actual->entry->id);
        $this->assertNotNull($journalEntry);
        $this->assertTrue(
            $journalEntry->transDate->equalTo(new Carbon($requestData['date']))
        );
        $this->assertEquals('CAD', $journalEntry->currency);
        $this->assertEquals($requestData['description'], $journalEntry->description);
        $ledgerDomain = LedgerDomain::find($journalEntry->domainUuid);
        $this->assertNotNull($ledgerDomain);
        $this->assertEquals('Corp', $ledgerDomain->code);

        $expectByCode = [
            '1310' => '-520.00',
            '4110' => '0.00',
            '1120' => '500.00',
            '2250' => '20.00',
        ];
        // Check all balances in the ledger
        foreach (LedgerAccount::all() as $ledgerAccount) {
            /** @var LedgerBalance $ledgerBalance */
            foreach ($ledgerAccount->balances as $ledgerBalance) {
                $this->assertEquals('CAD', $ledgerBalance->currency);
                $this->assertEquals(
                    $expectByCode[$ledgerAccount->code], $ledgerBalance->balance
                );
                unset($expectByCode[$ledgerAccount->code]);
            }
        }
        $this->assertCount(0, $expectByCode);
    }

    public function testDelete()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger and transaction
        $this->createLedger(['template'], ['template' => 'common']);

        [$requestData, $response] = $this->addSalesTransaction();
        $actual = $this->isSuccessful($response);

        // Get the created data
        $journalEntry = JournalEntry::find($actual->entry->id);
        $this->assertNotNull($journalEntry);
        $details = $journalEntry->details;
        $ledgerDomain = LedgerDomain::find($journalEntry->domainUuid);

        // Now delete the account
        $deleteData = [
            'id' => $actual->entry->id,
            'revision' => $actual->entry->revision,
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/entry/delete', $deleteData
        );
        $this->isSuccessful($response, 'success');

        // Confirm that records are deleted and balances corrected.
        $journalEntryDeleted = JournalEntry::find($actual->entry->id);
        $this->assertNull($journalEntryDeleted);
        foreach ($details as $detail) {
            $ledgerAccount = LedgerAccount::find($detail->ledgerUuid);
            $this->assertNotNull($ledgerAccount);
            $ledgerBalance = LedgerBalance::where([
                    ['ledgerUuid', '=', $detail->ledgerUuid],
                    ['domainUuid', '=', $ledgerDomain->domainUuid],
                    ['currency', '=', $journalEntry->currency]]
            )->first();
            $this->assertNotNull($ledgerBalance);
            $this->assertEquals('0.00', $ledgerBalance->balance);
        }
    }

    public function testGet()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger and transaction
        $this->createLedger(['template'], ['template' => 'common']);

        [$requestData, $addResponse] = $this->addSalesTransaction();
        $addActual = $this->isSuccessful($addResponse);

        // Get the created data by ID
        $fetchData = [
            'id' => $addActual->entry->id
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/entry/get', $fetchData
        );
        $fetched = $this->isSuccessful($response);
        $this->hasRevisionElements($fetched->entry);

        // Verify the contents
        $entry = $fetched->entry;
        $this->assertEquals($addActual->entry->id, $entry->id);
        $date = new Carbon($entry->date);
        $this->assertTrue(
            $date->equalTo(new Carbon($requestData['date']))
        );
        $this->assertEquals('CAD', $entry->currency);
        $this->assertEquals($requestData['description'], $entry->description);
        $expectDetails = [
            '1310' => '-520.00',
            '4110' => '520.00',
        ];
        foreach ($entry->details as $detail) {
            $this->assertArrayHasKey($detail->accountCode, $expectDetails);
            $this->assertEquals($expectDetails[$detail->accountCode], $detail->amount);
        }
    }

    public function testUpdate()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );
        // First we need a ledger and transaction
        $this->createLedger(['template'], ['template' => 'common']);

        [$requestData, $addResponse] = $this->addSalesTransaction();
        $addActual = $this->isSuccessful($addResponse);

        // Update the transaction
        $requestData['id'] = $addActual->entry->id;
        $requestData['revision'] = $addActual->entry->revision;
        $requestData['description'] = 'Oops, that was a rental!';
        $requestData['details'][1]['accountCode'] = '4240';
        $response = $this->json(
            'post', 'api/v1/ledger/entry/update', $requestData
        );
        $result = $this->isSuccessful($response);

        // Check that we really did do everything that was supposed to be done.
        $journalEntry = JournalEntry::find($addActual->entry->id);
        $this->assertNotNull($journalEntry);
        $this->assertTrue(
            $journalEntry->transDate->equalTo(new Carbon($requestData['date']))
        );
        $this->assertEquals('CAD', $journalEntry->currency);
        $this->assertEquals($requestData['description'], $journalEntry->description);
        $ledgerDomain = LedgerDomain::find($journalEntry->domainUuid);
        $this->assertNotNull($ledgerDomain);
        $this->assertEquals('Corp', $ledgerDomain->code);

        $expectByCode = [
            '1310' => '-520.00',
            '4110' => '0.00',
            '4240' => '520.00',
        ];
        // Check all balances in the ledger
        foreach (LedgerAccount::all() as $ledgerAccount) {
            /** @var LedgerBalance $ledgerBalance */
            foreach ($ledgerAccount->balances as $ledgerBalance) {
                $this->assertEquals('CAD', $ledgerBalance->currency);
                $this->assertEquals(
                    $expectByCode[$ledgerAccount->code],
                    $ledgerBalance->balance,
                    "For {$ledgerAccount->code}"
                );
                unset($expectByCode[$ledgerAccount->code]);
            }
        }
        $this->assertCount(0, $expectByCode);
    }

}
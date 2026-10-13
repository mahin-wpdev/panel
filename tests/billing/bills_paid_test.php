<?php
declare(strict_types=1);

final class BillField implements ArrayAccess
{
    private array $row;
    public function __construct(array $row = []) { $this->row = $row; }
    public function offsetExists(mixed $offset): bool { return isset($this->row[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->row[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->row[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->row[$offset]); }
    public function save(): void
    {
        if (empty($this->row['id'])) $this->row['id'] = ++ORM::$lastId;
        ORM::$fields[$this->row['id']] = $this->row;
    }
    public function id(): int { return (int)($this->row['id'] ?? 0); }
}

final class BillQuery
{
    private array $filters = [];
    public function where(string $field, mixed $value): self
    {
        $this->filters[$field] = $value;
        return $this;
    }
    private function ids(): array
    {
        $ids = [];
        foreach (ORM::$fields as $id => $field) {
            foreach ($this->filters as $key => $value) {
                if (($field[$key] ?? null) != $value) continue 2;
            }
            $ids[] = $id;
        }
        return $ids;
    }
    public function find_one(): ?BillField
    {
        $id = $this->ids()[0] ?? null;
        return $id === null ? null : new BillField(ORM::$fields[$id]);
    }
    public function delete_many(): int
    {
        $ids = $this->ids();
        foreach ($ids as $id) unset(ORM::$fields[$id]);
        return count($ids);
    }
    public function create(): BillField { return new BillField($this->filters); }
}

final class ORM
{
    public static array $fields = [];
    public static int $lastId = 0;
    public static function for_table(string $table): BillQuery { return new BillQuery(); }
}

require __DIR__.'/../../system/autoload/User.php';

ORM::$fields = [
    1 => ['id'=>1,'customer_id'=>7,'field_name'=>'Setup Bill','field_value'=>'250'],
    2 => ['id'=>2,'customer_id'=>7,'field_name'=>'Router Bill','field_value'=>'300:2'],
    3 => ['id'=>3,'customer_id'=>7,'field_name'=>'ONU Bill','field_value'=>'400:1'],
    4 => ['id'=>4,'customer_id'=>8,'field_name'=>'Other Bill','field_value'=>'900'],
];
ORM::$lastId = 4;

User::billsPaid(['Setup Bill'=>250,'Router Bill'=>300,'ONU Bill'=>400], 7);

if (isset(ORM::$fields[1]) || isset(ORM::$fields[3]) || ORM::$fields[2]['field_value'] !== '300:1') {
    throw new RuntimeException('Paid one-off/final installment bills must be removed and remaining installments decremented: '.json_encode(ORM::$fields));
}
if (!isset(ORM::$fields[4])) throw new RuntimeException('Bill settlement must not mutate another customer.');
echo "PASS: one-off bills clear; installments decrement and clear when complete\n";

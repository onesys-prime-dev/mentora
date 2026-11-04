<?php

namespace App\Models\Messaging\Concerns;

use LogicException;

/**
 * For a table whose primary key is composite. The model's `$primaryKey` can name only one of the columns, so
 * find(), update() and delete() by id would act on every row sharing that one value. They refuse an id:
 * constrain BOTH columns with where() and call them without one.
 */
trait RefusesIdLookups
{
    public function find($id = null)
    {
        $this->refuseId($id, 'find');

        return parent::find();
    }

    public function update($id = null, $row = null): bool
    {
        $this->refuseId($id, 'update');

        return parent::update(null, $row);
    }

    public function delete($id = null, bool $purge = false)
    {
        $this->refuseId($id, 'delete');

        return parent::delete(null, $purge);
    }

    private function refuseId(mixed $id, string $method): void
    {
        if ($id !== null) {
            throw new LogicException(static::class . "::{$method}() by id is not allowed: the key is composite, so constrain both columns with where().");
        }
    }
}

<?php

declare(strict_types=1);

namespace Pandawa\Component\Transformer;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Pandawa\Component\Transformer\Exception\IncludeNotAllowedException;
use Pandawa\Component\Transformer\Exception\SelectNotAllowedException;
use Pandawa\Contracts\Transformer\Context;
use Pandawa\Contracts\Transformer\TransformerInterface;
use RuntimeException;

/**
 * @author  Iqbal Maulana <iq.bluejack@gmail.com>
 */
abstract class Transformer implements TransformerInterface
{
    use ConditionallyTrait;

    /**
     * Data wrap.
     *
     * @var string|null
     */
    protected ?string $wrapper = null;

    /**
     * Available include relations.
     *
     * @var array
     */
    protected array $availableIncludes = [];

    /**
     * Default include relations.
     *
     * @var array
     */
    protected array $defaultIncludes = [];

    /**
     * Available select properties.
     *
     * @var array
     */
    protected array $availableSelects = [];

    /**
     * Default selected properties.
     *
     * @var array
     */
    protected array $defaultSelects = [];

    public function setAvailableIncludes(array $availableIncludes): static
    {
        $this->availableIncludes = $availableIncludes;

        return $this;
    }

    public function setDefaultIncludes(array $defaultIncludes): static
    {
        $this->defaultIncludes = $defaultIncludes;

        return $this;
    }

    public function setAvailableSelects(array $availableSelects): static
    {
        $this->availableSelects = $availableSelects;

        return $this;
    }

    public function setDefaultSelects(array $defaultSelects): static
    {
        $this->defaultSelects = $defaultSelects;

        return $this;
    }

    public function setWrapper(?string $wrapper): void
    {
        $this->wrapper = $wrapper;
    }

    public function getWrapper(): ?string
    {
        return $this->wrapper;
    }

    public function process(Context $context, mixed $data): mixed
    {
        $result = $this->processTransform($context, $data);

        if (!is_array($result)) {
            return $result;
        }

        $transformed = [
            ...$result,
            ...$this->processIncludes(
                $context,
                $this->getIncludes($context->includes),
                $data
            ),
        ];

        if (empty($selects = $this->getSelects($context->selects))) {
            return $transformed;
        }

        return $this->filter($transformed, $selects);
    }

    public function wrap(mixed $data): mixed
    {
        if (null === $this->wrapper) {
            return $data;
        }

        return [$this->wrapper => $data];
    }

    public function getSelects(array $selects): array
    {
        if (empty($selects)) {
            return $this->defaultSelects;
        }

        if (empty($this->availableSelects)) {
            return $selects;
        }

        return array_filter($selects, function (string $select) {
            if ($this->isAllowed($select, $this->availableSelects)) {
                return true;
            }

            throw new SelectNotAllowedException($select);
        });
    }

    public function getIncludes(array $includes): array
    {
        if (empty($includes)) {
            return $this->defaultIncludes;
        }

        if (empty($this->availableIncludes)) {
            return $includes;
        }

        return array_filter($includes, function (string $include) {
            if (in_array($include, $this->availableIncludes)) {
                return true;
            }

            throw new IncludeNotAllowedException($include);
        });
    }

    protected function processIncludes(Context $context, array $includes, mixed $data): array
    {
        $included = [];
        foreach ($includes as $include) {
            $method = 'include' . ucfirst(Str::camel(str_replace('.', '_', $include)));

            if (!method_exists($this, $method)) {
                throw new RuntimeException(sprintf(
                    'Missing method "%s" in transformer class "%s".',
                    $method,
                    static::class
                ));
            }

            $included[$include] = $this->{$method}($context, $data);
        }

        return Arr::undot($included);
    }

    protected function processTransform(Context $context, mixed $data): mixed
    {
        if (!method_exists($this, 'transform')) {
            throw new RuntimeException(sprintf('Class "%s" should has transform method.', static::class));
        }

        $normalized = [];

        $transformed = $this->transform($context, $data);

        if (!is_array($transformed)) {
            return $transformed;
        }

        foreach ($transformed as $key => $value) {
            if ($value instanceof MissingValue) {
                continue;
            }

            if ($value instanceof MergeValue) {
                $normalized = [...$normalized, ...$value->data];

                continue;
            }

            $normalized[$key] = $value;
        }

        if (!empty($this->availableSelects)) {
            $normalized = $this->filter($normalized, $this->availableSelects);
        }

        return $normalized;
    }

    protected function isAllowed(string $select, array $stack): bool
    {
        $temp = null;
        do {
            $temp = null === $temp ? $select : substr($temp, 0, strrpos($temp, '.'));

            if (in_array($temp, $stack)) {
                return true;
            }

        } while($temp && str_contains($temp, '.'));

        return false;
    }

    protected function filter(array $data, array $keys): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if (is_array($value) && !empty($childKeys = $this->filterKeys($key, $keys))) {
                if (empty($value = $this->filter($value, $childKeys))) {
                    continue;
                }

                $filtered[$key] = $value;

                continue;
            }

            if ($this->isAllowed($key, $keys)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    protected function filterKeys(string|int $filter, array $keys): array
    {
        if (is_int($filter)) {
            return $keys;
        }

        $filtered = [];
        foreach ($keys as $key) {
            if (preg_match('/^'.$filter.'\./', $key)) {
                $filtered[] = str_replace($filter.'.', '', $key);
            }
        }

        return $filtered;
    }
}

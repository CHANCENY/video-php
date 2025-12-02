<?php

namespace Simp\VideoPhp\batch;

use Symfony\Component\Yaml\Yaml;

class BatchRule
{
    protected array $rules = [];

    public function __construct()
    {
        $files = __DIR__ . '/../rules';
        if (is_dir($files)) {
            $rules = array_diff(scandir($files), ['.', '..']);
            foreach ($rules as $rule) {
                $fullRule = $files . DIRECTORY_SEPARATOR . $rule;
                $name = pathinfo($fullRule, PATHINFO_FILENAME);
                $this->rules[$name] = Yaml::parseFile($fullRule);
            }
        }
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function getRule(string $name): array
    {
        return $this->rules[$name];
    }

    public function hasRule(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    public function mergeRules(...$rules_name): array
    {
        $merged = [];
        foreach ($rules_name as $rule_name) {
            $merged = array_merge($this->rules, $this->getRule($rule_name));
        }
        return $merged;
    }
}
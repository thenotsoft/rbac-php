<?php

declare(strict_types=1);

namespace Yiisoft\Rbac\Php;

use Yiisoft\Rbac\Assignment;
use Yiisoft\Rbac\Item;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;
use Yiisoft\Rbac\Rule;
use Yiisoft\Rbac\StorageInterface;
use Yiisoft\VarDumper\VarDumper;

/**
 * Storage stores authorization data in three PHP files specified by {@see Storage::itemFile},
 * {@see Storage::assignmentFile} and {@see Storage::ruleFile}.
 *
 * It is mainly suitable for authorization data that is not too big (for example, the authorization data for
 * a personal blog system).
 *
 * @package Yiisoft\Rbac\Php
 */
final class Storage implements StorageInterface
{
    /**
     * @var string The path of the PHP script that contains the authorization items.
     * This can be either a file path or a [path alias](guide:concept-aliases) to the file.
     * Make sure this file is writable by the Web server process if the authorization needs to be changed
     * online.
     *
     * @see loadFromFile()
     * @see saveToFile()
     */
    private string $itemFile;
    /**
     * @var string The path of the PHP script that contains the authorization assignments.
     * This can be either a file path or a [path alias](guide:concept-aliases) to the file.
     * Make sure this file is writable by the Web server process if the authorization needs to be changed
     * online.
     *
     * @see loadFromFile()
     * @see saveToFile()
     */
    private string $assignmentFile;
    /**
     * @var string The path of the PHP script that contains the authorization rules.
     * This can be either a file path or a [path alias](guide:concept-aliases) to the file.
     * Make sure this file is writable by the Web server process if the authorization needs to be changed
     * online.
     *
     * @see loadFromFile()
     * @see saveToFile()
     */
    private string $ruleFile;

    /**
     * @var Item[]
     * Format is [itemName => item].
     */
    private array $items = [];

    /**
     * @var array
     * Format is [itemName => [childName => child]].
     */
    private array $children = [];

    /**
     * @var array
     * Format is [userId => [itemName => assignment]].
     */
    private array $assignments = [];

    /**
     * @var Rule[]
     * Format is [ruleName => rule].
     */
    private array $rules = [];

    public function __construct(
        string $directory,
        string $itemFile = 'items.php',
        string $assignmentFile = 'assignments.php',
        string $ruleFile = 'rules.php'
    ) {
        $this->itemFile = $directory . DIRECTORY_SEPARATOR . $itemFile;
        $this->assignmentFile = $directory . DIRECTORY_SEPARATOR . $assignmentFile;
        $this->ruleFile = $directory . DIRECTORY_SEPARATOR . $ruleFile;
        $this->load();
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getItemByName(string $name): ?Item
    {
        return $this->items[$name] ?? null;
    }

    public function addItem(Item $item): void
    {
        $this->items[$item->getName()] = $item;
        $this->saveItems();
    }

    public function getRoleByName(string $name): ?Role
    {
        return $this->getItemsByType(Item::TYPE_ROLE)[$name] ?? null;
    }

    public function getRoles(): array
    {
        return $this->getItemsByType(Item::TYPE_ROLE);
    }

    public function getPermissionByName(string $name): ?Permission
    {
        return $this->getItemsByType(Item::TYPE_PERMISSION)[$name] ?? null;
    }

    public function getPermissions(): array
    {
        return $this->getItemsByType(Item::TYPE_PERMISSION);
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function getChildrenByName(string $name): array
    {
        return $this->children[$name] ?? [];
    }

    public function getAssignments(): array
    {
        return $this->assignments;
    }

    public function getUserAssignments(string $userId): array
    {
        return $this->assignments[$userId] ?? [];
    }

    public function getUserAssignmentByName(string $userId, string $name): ?Assignment
    {
        return $this->getUserAssignments($userId)[$name] ?? null;
    }

    public function getRules(): array
    {
        return $this->rules;
    }

    public function getRuleByName(string $name): ?Rule
    {
        return $this->rules[$name] ?? null;
    }

    public function addChild(Item $parent, Item $child): void
    {
        $this->children[$parent->getName()][$child->getName()] = $this->items[$child->getName()];
        $this->saveItems();
    }

    public function hasChildren(string $name): bool
    {
        return isset($this->children[$name]);
    }

    public function removeChild(Item $parent, Item $child): void
    {
        unset($this->children[$parent->getName()][$child->getName()]);
        $this->saveItems();
    }

    public function removeChildren(Item $parent): void
    {
        unset($this->children[$parent->getName()]);
        $this->saveItems();
    }

    public function addAssignment(string $userId, Item $item): void
    {
        $this->assignments[$userId][$item->getName()] = new Assignment($userId, $item->getName(), time());
        $this->saveAssignments();
    }

    public function assignmentExist(string $name): bool
    {
        foreach ($this->getAssignments() as $assignmentInfo) {
            foreach ($assignmentInfo as $itemName => $assignment) {
                if ($itemName === $name) {
                    return true;
                }
            }
        }
        return false;
    }

    public function removeAssignment(string $userId, Item $item): void
    {
        unset($this->assignments[$userId][$item->getName()]);
        $this->saveAssignments();
    }

    public function removeAllAssignments(string $userId): void
    {
        $this->assignments[$userId] = [];
        $this->saveAssignments();
    }

    public function removeItem(Item $item): void
    {
        $this->clearAssigmentFromItem($item);
        $this->saveAssignments();
        $this->clearChildrenFromItem($item);
        $this->removeItemByName($item->getName());
        $this->saveItems();
    }

    public function updateItem(string $name, Item $item): void
    {
        if ($item->getName() !== $name) {
            $this->updateItemName($name, $item);
            $this->removeItemByName($name);
        }

        $this->addItem($item);
    }

    public function removeRule(string $name): void
    {
        unset($this->rules[$name]);
        foreach ($this->getItemsByRuleName($name) as $item) {
            $item = $item->withRuleName(null);
            $this->updateItem($item->getName(), $item);
        }

        $this->saveRules();
    }

    public function addRule(Rule $rule): void
    {
        $this->rules[$rule->getName()] = $rule;
        $this->saveRules();
    }

    public function clear(): void
    {
        $this->clearLoadedData();
        $this->save();
    }

    public function clearRules(): void
    {
        $this->clearItemsFromRules();
        $this->rules = [];
        $this->saveRules();
    }

    public function clearAssignments(): void
    {
        $this->assignments = [];
        $this->saveAssignments();
    }

    public function clearPermissions(): void
    {
        $this->removeAllItems(Item::TYPE_PERMISSION);
    }

    public function clearRoles(): void
    {
        $this->removeAllItems(Item::TYPE_ROLE);
    }

    private function updateItemName(string $name, Item $item): void
    {
        $this->updateChildrenForItemName($name, $item);
        $this->updateAssignmentsForItemName($name, $item);
        $this->saveAssignments();
    }

    /**
     * Saves authorization data into persistent storage.
     */
    private function save(): void
    {
        $this->saveItems();
        $this->saveAssignments();
        $this->saveRules();
    }

    /**
     * Loads authorization data from persistent storage.
     */
    private function load(): void
    {
        $this->clearLoadedData();
        $this->loadItems();
        $this->loadAssignments();
        $this->loadRules();
    }

    private function loadItems(): void
    {
        $items = $this->loadFromFile($this->itemFile);
        $itemsMtime = @filemtime($this->itemFile);
        foreach ($items as $name => $item) {
            $this->items[$name] = $this->getInstanceFromAttributes($item)
                ->withCreatedAt($itemsMtime)
                ->withUpdatedAt($itemsMtime);
        }

        foreach ($items as $name => $item) {
            if (isset($item['children'])) {
                foreach ($item['children'] as $childName) {
                    if ($this->hasItem($childName)) {
                        $this->children[$name][$childName] = $this->items[$childName];
                    }
                }
            }
        }
    }

    private function loadAssignments(): void
    {
        $assignments = $this->loadFromFile($this->assignmentFile);
        $assignmentsMtime = @filemtime($this->assignmentFile);
        foreach ($assignments as $userId => $roles) {
            foreach ($roles as $role) {
                $this->assignments[$userId][$role] = new Assignment($userId, $role, $assignmentsMtime);
            }
        }
    }

    private function loadRules(): void
    {
        foreach ($this->loadFromFile($this->ruleFile) as $name => $ruleData) {
            $this->rules[$name] = $this->unserializeRule($ruleData);
        }
    }

    private function clearLoadedData(): void
    {
        $this->children = [];
        $this->rules = [];
        $this->assignments = [];
        $this->items = [];
    }

    private function hasItem(string $name): bool
    {
        return isset($this->items[$name]);
    }

    /**
     * Loads the authorization data from a PHP script file.
     *
     * @param string $file The file path.
     *
     * @return array The authorization data.
     *
     * @see saveToFile()
     */
    private function loadFromFile(string $file): array
    {
        if (is_file($file)) {
            return require $file;
        }

        return [];
    }

    /**
     * Saves items data into persistent storage.
     */
    private function saveItems(): void
    {
        $items = [];
        foreach ($this->getItems() as $name => $item) {
            $items[$name] = array_filter($item->getAttributes());
            if ($this->hasChildren($name)) {
                foreach ($this->getChildrenByName($name) as $child) {
                    $items[$name]['children'][] = $child->getName();
                }
            }
        }
        $this->saveToFile($items, $this->itemFile);
    }

    /**
     * Saves the authorization data to a PHP script file.
     *
     * @param array $data The authorization data
     * @param string $file The file path.
     *
     * @see loadFromFile()
     */
    private function saveToFile(array $data, string $file): void
    {
        if (!file_exists(dirname($file)) && !mkdir($concurrentDirectory = dirname($file)) && !is_dir(
            $concurrentDirectory
        )) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        file_put_contents($file, "<?php\nreturn " . VarDumper::create($data)->export() . ";\n", LOCK_EX);
        $this->invalidateScriptCache($file);
    }

    /**
     * Invalidates precompiled script cache (such as OPCache) for the given file.
     *
     * @param string $file The file path.
     */
    private function invalidateScriptCache(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * Saves assignments data into persistent storage.
     */
    private function saveAssignments(): void
    {
        $assignmentData = [];
        foreach ($this->assignments as $userId => $assignments) {
            foreach ($assignments as $assignment) {
                /* @var $assignment Assignment */
                $assignmentData[$userId][] = $assignment->getItemName();
            }
        }
        $this->saveToFile($assignmentData, $this->assignmentFile);
    }

    /**
     * Saves rules data into persistent storage.
     */
    private function saveRules(): void
    {
        $this->saveToFile($this->serializeRules(), $this->ruleFile);
    }

    private function getItemsByType(string $type): array
    {
        return $this->filterItems(
            fn (Item $item) => $item->getType() === $type
        );
    }

    private function getItemsByRuleName(string $ruleName): array
    {
        return $this->filterItems(
            fn (Item $item) => $item->getRuleName() === $ruleName
        );
    }

    /**
     * @param callable $callback
     * @return array|Item[]
     */
    private function filterItems(callable $callback): array
    {
        return array_filter($this->getItems(), $callback);
    }

    /**
     * Removes all auth items of the specified type.
     *
     * @param string $type The auth item type (either {@see Item::TYPE_PERMISSION} or {@see Item::TYPE_ROLE}).
     */
    private function removeAllItems(string $type): void
    {
        foreach ($this->getItemsByType($type) as $name => $item) {
            $this->removeItem($item);
        }
    }

    private function clearChildrenFromItem(Item $item): void
    {
        foreach ($this->children as &$children) {
            unset($children[$item->getName()]);
        }
    }

    private function clearAssigmentFromItem(Item $item): void
    {
        foreach ($this->assignments as &$assignments) {
            unset($assignments[$item->getName()]);
        }
    }

    private function getInstanceByTypeAndName(string $type, string $name): Item
    {
        return $type === Item::TYPE_PERMISSION ? new Permission($name) : new Role($name);
    }

    private function getInstanceFromAttributes(array $attributes): Item
    {
        return $this
            ->getInstanceByTypeAndName($attributes['type'], $attributes['name'])
            ->withDescription($attributes['description'] ?? '')
            ->withRuleName($attributes['ruleName'] ?? null);
    }

    private function serializeRules(): array
    {
        return array_map(fn (Rule $rule): string => serialize($rule), $this->rules);
    }

    private function unserializeRule(string $data): Rule
    {
        return unserialize($data, ['allowed_classes' => true]);
    }

    private function updateAssignmentsForItemName(string $name, Item $item): void
    {
        foreach ($this->assignments as &$assignments) {
            if (isset($assignments[$name])) {
                $assignments[$item->getName()] = $assignments[$name]->withItemName($item->getName());
                unset($assignments[$name]);
            }
        }
    }

    private function updateChildrenForItemName(string $name, Item $item): void
    {
        if ($this->hasChildren($name)) {
            $this->children[$item->getName()] = $this->children[$name];
            unset($this->children[$name]);
        }
        foreach ($this->children as &$children) {
            if (isset($children[$name])) {
                $children[$item->getName()] = $children[$name];
                unset($children[$name]);
            }
        }
    }

    private function removeItemByName(string $name): void
    {
        unset($this->items[$name]);
    }

    private function clearItemsFromRules(): void
    {
        foreach ($this->items as &$item) {
            $item = $item->withRuleName(null);
        }
    }
}

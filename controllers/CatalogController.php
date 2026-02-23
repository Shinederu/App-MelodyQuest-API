<?php

require_once __DIR__ . '/../services/CatalogService.php';
require_once __DIR__ . '/../utils/response.php';

class CatalogController
{
    private CatalogService $service;

    public function __construct()
    {
        $this->service = new CatalogService();
    }

    public function listCategories(): void
    {
        json_success(null, ['items' => $this->service->listCategories()]);
    }

    public function listFamilies(array $payload): void
    {
        $categoryId = isset($payload['category_id']) ? (int)$payload['category_id'] : (isset($_GET['category_id']) ? (int)$_GET['category_id'] : null);
        json_success(null, ['items' => $this->service->listFamilies($categoryId ?: null)]);
    }

    public function listTracks(array $payload): void
    {
        $familyId = isset($payload['family_id']) ? (int)$payload['family_id'] : (isset($_GET['family_id']) ? (int)$_GET['family_id'] : null);
        json_success(null, ['items' => $this->service->listTracks($familyId ?: null)]);
    }

    public function createCategory(int $userId, array $payload): void
    {
        $result = $this->service->createCategory($userId, $payload);
        json_success('Categorie creee', $result, 201);
    }

    public function createFamily(int $userId, array $payload): void
    {
        $result = $this->service->createFamily($userId, $payload);
        json_success('Famille creee', $result, 201);
    }

    public function createTrack(int $userId, array $payload): void
    {
        $result = $this->service->createTrack($userId, $payload);
        json_success('Musique ajoutee', $result, 201);
    }

    public function updateCategory(array $payload): void
    {
        $result = $this->service->updateCategory($payload);
        json_success('Categorie mise a jour', $result);
    }

    public function updateFamily(array $payload): void
    {
        $result = $this->service->updateFamily($payload);
        json_success('Famille mise a jour', $result);
    }

    public function updateTrack(array $payload): void
    {
        $result = $this->service->updateTrack($payload);
        json_success('Musique mise a jour', $result);
    }

    public function deleteCategory(array $payload): void
    {
        $id = (int)($payload['id'] ?? 0);
        $result = $this->service->deleteCategory($id);
        json_success('Categorie supprimee', $result);
    }

    public function deleteFamily(array $payload): void
    {
        $id = (int)($payload['id'] ?? 0);
        $result = $this->service->deleteFamily($id);
        json_success('Famille supprimee', $result);
    }

    public function deleteTrack(array $payload): void
    {
        $id = (int)($payload['id'] ?? 0);
        $result = $this->service->deleteTrack($id);
        json_success('Musique supprimee', $result);
    }
}

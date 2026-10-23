<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\ReservationFilter;
use App\Domain\ReservationInput;
use App\Domain\ReservationService;
use App\Infrastructure\ReservationRepository;
use Twig\Environment;

final readonly class ReservationController
{
    public function __construct(
        private ReservationRepository $repository,
        private ReservationService $service,
        private Environment $twig,
        private string $today,
    ) {
    }

    public function listing(Request $request): Response
    {
        return Response::json($this->repository->listing(ReservationFilter::fromQuery($request->query)));
    }

    public function detail(int $id): Response
    {
        return Response::json(['data' => $this->service->detail($id)]);
    }

    public function create(Request $request): Response
    {
        $reservation = $this->service->create(ReservationInput::fromArray($request->json()));
        return Response::json(['data' => $reservation], 201, ['Location' => '/api/reservas/' . $reservation['id']]);
    }

    public function changeStatus(int $id, Request $request): Response
    {
        return Response::json(['data' => $this->service->changeStatus($id, $request->json())]);
    }

    public function update(int $id, Request $request): Response
    {
        return Response::json(['data' => $this->service->update($id, $request->json())]);
    }

    public function bulkStatus(Request $request): Response
    {
        return Response::json($this->service->bulkStatus($request->json()));
    }

    public function overview(): Response
    {
        return Response::json(['data' => $this->repository->overview($this->today), 'today' => $this->today,
            'accommodations' => $this->repository->accommodations()]);
    }

    public function export(Request $request): Response
    {
        return CsvExport::response($this->repository->export(ReservationFilter::fromQuery($request->query)));
    }

    public function agenda(Request $request): Response
    {
        $from = $request->query['desde'] ?? $this->today;
        $to = $request->query['hasta'] ?? (new \DateTimeImmutable($this->today))->modify('+6 days')->format('Y-m-d');
        if (!is_string($from) || !is_string($to) || !ReservationInput::validDate($from) || !ReservationInput::validDate($to)
            || $to < $from || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 30) {
            throw new HttpException(400, 'Selecciona un intervalo de agenda válido de hasta 31 días.');
        }
        return Response::json($this->repository->agenda($from, $to));
    }

    public function indexPage(Request $request): Response
    {
        $filter = ReservationFilter::fromQuery($request->query);
        return new Response($this->twig->render(
            'reservations/index.html.twig',
            $this->repository->listing($filter) + ['filters' => $filter, 'overview' => $this->repository->overview($this->today),
                'accommodations' => $this->repository->accommodations(), 'initial_view' => ($request->query['vista'] ?? '') === 'agenda' ? 'agenda' : 'list']
        ));
    }

    public function detailPage(int $id): Response
    {
        return new Response($this->twig->render('reservations/detail.html.twig', ['reservation' => $this->service->detail($id)]));
    }
}

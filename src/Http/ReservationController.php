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
    ) {}

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

    public function indexPage(Request $request): Response
    {
        $filter = ReservationFilter::fromQuery($request->query);
        return new Response($this->twig->render('reservations/index.html.twig',
            $this->repository->listing($filter) + ['filters' => $filter]));
    }

    public function detailPage(int $id): Response
    {
        return new Response($this->twig->render('reservations/detail.html.twig', ['reservation' => $this->service->detail($id)]));
    }
}

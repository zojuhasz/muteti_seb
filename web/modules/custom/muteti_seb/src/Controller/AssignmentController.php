<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AssignmentController extends ControllerBase {
  public function __construct(private readonly Connection $database) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('database')); }
  public function update(Request $request): JsonResponse {
    $data=json_decode($request->getContent(),TRUE); $id=(int)($data['id']??0);$room=(string)($data['room']??'');$date=(string)($data['date']??'');$ids=array_map('intval',$data['ordered_ids']??[]);
    if(!$id)return new JsonResponse(['error'=>'Missing id'],400);
    $this->database->update('muteti_appointment')->fields(['operating_room'=>$room?:NULL,'surgery_date'=>$date?:NULL,'changed'=>\Drupal::time()->getRequestTime()])->condition('id',$id)->execute();
    foreach($ids as $order=>$appointment_id)$this->database->update('muteti_appointment')->fields(['surgery_order'=>$order+1])->condition('id',$appointment_id)->execute();
    return new JsonResponse(['ok'=>TRUE]);
  }
}

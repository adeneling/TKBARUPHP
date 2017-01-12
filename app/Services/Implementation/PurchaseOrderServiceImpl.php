<?php
/**
 * Created by PhpStorm.
 * User: miftah.fathudin
 * Date: 11/13/2016
 * Time: 2:26 AM
 */

namespace App\Services\Implementation;

use App\Model\Item;
use App\Model\Lookup;
use App\Model\Expense;
use App\Model\ProductUnit;
use App\Model\PurchaseOrder;

use App\Services\PurchaseOrderService;

use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Doctrine\Common\Collections\Collection;

class PurchaseOrderServiceImpl implements PurchaseOrderService
{
    /**
     * Save(create) a newly purchase order. The saved(created) purchase order will be returned.
     *
     * @param Request $request request which contains values from create form to create the purchase order.
     * @return PurchaseOrder
     */
    public function createPO(Request $request)
    {
        DB::transaction(function() use ($request) {
            if ($request->input('supplier_type') == 'SUPPLIERTYPE.R'){
                $supplier_id = empty($request->input('supplier_id')) ? 0 : $request->input('supplier_id');
                $walk_in_supplier = '';
                $walk_in_supplier_detail = '';
            } else {
                $supplier_id = 0;
                $walk_in_supplier = $request->input('walk_in_supplier');
                $walk_in_supplier_detail = $request->input('walk_in_supplier_detail');
            }

            $params = [
                'code' => $request->input('code'),
                'po_type' => $request->input('po_type'),
                'po_created' => date('Y-m-d H:i:s', strtotime($request->input('po_created'))),
                'shipping_date' => date('Y-m-d H:i:s', strtotime($request->input('shipping_date'))),
                'supplier_type' => $request->input('supplier_type'),
                'walk_in_supplier' => $walk_in_supplier,
                'walk_in_supplier_detail' => $walk_in_supplier_detail,
                'remarks' => $request->input('remarks'),
                'status' => Lookup::whereCode('POSTATUS.WA')->first()->code,
                'supplier_id' => $supplier_id,
                'vendor_trucking_id' => empty($request->input('vendor_trucking_id')) ? 0 : $request->input('vendor_trucking_id'),
                'warehouse_id' => $request->input('warehouse_id'),
                'store_id' => Auth::user()->store_id
            ];

            $po = PurchaseOrder::create($params);

            for ($i = 0; $i < count($request->input('product_id')); $i++) {
                $item = new Item();
                $item->product_id = $request->input("product_id.$i");
                $item->store_id = Auth::user()->store_id;
                $item->selected_unit_id = $request->input("selected_unit_id.$i");
                $item->base_unit_id = $request->input("base_unit_id.$i");
                $item->conversion_value = ProductUnit::where([
                    'product_id' => $item->product_id,
                    'unit_id' => $item->selected_unit_id
                ])->first()->conversion_value;
                $item->quantity = $request->input("quantity.$i");
                $item->price = floatval(str_replace(',', '', $request->input("price.$i")));
                $item->to_base_quantity = $item->quantity * $item->conversion_value;

                $po->items()->save($item);
            }

            for($i = 0; $i < count($request->input('expense_name')); $i++){
                $expense = new Expense();
                $expense->name = $request->input("expense_name.$i");
                $expense->type = $request->input("expense_type.$i");
                $expense->is_internal_expense = !empty($request->input("is_internal_expense.$i"));
                $expense->amount = floatval(str_replace(',', '', $request->input("expense_amount.$i")));
                $expense->remarks = $request->input("expense_remarks.$i");
                $po->expenses()->save($expense);
            }

            return $po;
        });
    }

    /**
     * Get purchase order to be revised.
     *
     * @param int $id id of purchase order to be revised.
     * @return PurchaseOrder purchase order to be revised.
     */
    public function getPOForRevise($id)
    {
        return PurchaseOrder::with('items.product.productUnits.unit', 'supplier.profiles.phoneNumbers.provider',
            'supplier.bankAccounts.bank', 'supplier.products.productUnits.unit', 'supplier.products.type',
            'supplier.expenseTemplates', 'vendorTrucking', 'warehouse', 'expenses')->find($id);
    }

    /**
     * Revise(modify) a purchase order. If the purchase order is still waiting for arrival, it's warehouse,
     * vendor trucking, shipping date and items can be changed. But, if it is already waiting for payment,
     * only it's items price can be changed. The revised(modified) purchase order will be returned.
     *
     * @param Request $request request which contains values from revise form to revise the purchase order.
     * @param int $id the id of purchase order to be revised.
     * @return PurchaseOrder
     */
    public function revisePO(Request $request, $id)
    {
        DB::transaction(function() use ($id, $request) {
            // Get current PO
            $currentPo = PurchaseOrder::with('items')->find($id);

            // Get IDs of current PO's items
            $poItemsId = $currentPo->items->map(function ($item) {
                return $item->id;
            })->all();

            $inputtedItemId = $request->input('item_id');

            // Get the id of removed items
            $poItemsToBeDeleted = array_diff($poItemsId, isset($inputtedItemId) ? $inputtedItemId : []);

            // Remove the items that removed on the revise page
            Item::destroy($poItemsToBeDeleted);

            $currentPo->shipping_date = date('Y-m-d H:i:s', strtotime($request->input('shipping_date')));
            $currentPo->warehouse_id = $request->input('warehouse_id');
            $currentPo->vendor_trucking_id = empty($request->input('vendor_trucking_id')) ? 0 : $request->input('vendor_trucking_id');
            $currentPo->remarks = $request->input('remarks');

            for ($i = 0; $i < count($request->input('item_id')); $i++) {
                $item = Item::findOrNew($request->input("item_id.$i"));
                $item->product_id = $request->input("product_id.$i");
                $item->store_id = Auth::user()->store_id;
                $item->selected_unit_id = $request->input("selected_unit_id.$i");
                $item->base_unit_id = $request->input("base_unit_id.$i");
                $item->conversion_value = ProductUnit::where([
                    'product_id' => $item->product_id,
                    'unit_id' => $item->selected_unit_id
                ])->first()->conversion_value;
                $item->quantity = $request->input("quantity.$i");
                $item->price = floatval(str_replace(',', '', $request->input("price.$i")));
                $item->to_base_quantity = $item->quantity * $item->conversion_value;

                $currentPo->items()->save($item);
            }

            // Get IDs of current PO's expenses
            $poExpensesId = $currentPo->expenses->map(function ($expense) {
                return $expense->id;
            })->all();

            $inputtedExpenseId = $request->input('expense_id');

            // Get the id of removed expenses
            $poExpensesToBeDeleted = array_diff($poExpensesId, isset($inputtedExpenseId) ? $inputtedExpenseId : []);

            // Remove the expenses that removed on the revise page
            Expense::destroy($poExpensesToBeDeleted);

            for($i = 0; $i < count($request->input('expense_id')); $i++){
                $expense = Expense::findOrNew($request->input("expense_id.$i"));
                $expense->name = $request->input("expense_name.$i");
                $expense->type = $request->input("expense_type.$i");
                $expense->is_internal_expense = !empty($request->input("is_internal_expense.$i"));
                $expense->amount = floatval(str_replace(',', '', $request->input("expense_amount.$i")));
                $expense->remarks = $request->input("expense_remarks.$i");

                $currentPo->expenses()->save($expense);
            }

            $currentPo->save();

            return $currentPo;
        });
    }

    /**
     * Reject a purchase order. Only purchase orders with status waiting for arrival can be rejected.
     *
     * @param Request $request request which contains values for purchase order rejection.
     * @param int $id the id of purchase order to be rejected.
     * @return void
     */
    public function rejectPO(Request $request, $id)
    {
        $po = PurchaseOrder::find($id);
        $po->status = 'POSTATUS.RJT';
        $po->save();
    }

    /**
     * Get purchase order which items want to be received.
     *
     * @param int $poId id of purchase order which items want to be received.
     * @return PurchaseOrder purchase order which items want to be received.
     */
    public function getPOForReceipt($poId)
    {
        return PurchaseOrder::with('items.product.productUnits.unit')->find($poId);
    }

    /**
     * Get all purchase order which belongs to warehouse with given id.
     *
     * @param int $warehouseId id of warehouse owning the purchase order(s).
     * @return Collection purchase orders of given warehouse.
     */
    public function getWarehousePO($warehouseId)
    {
        return PurchaseOrder::with('supplier')
            ->where('status', '=', 'POSTATUS.WA')
            ->where('warehouse_id', '=', $warehouseId)
            ->get();
    }

    /**
     * Get a purchase order with it's details related to payment.
     *
     * @param int $poId id of purchase order.
     * @return PurchaseOrder
     */
    public function getPOForPayment($poId)
    {
        return PurchaseOrder::with('payments', 'items.product.productUnits.unit',
            'supplier.profiles.phoneNumbers.provider', 'supplier.bankAccounts.bank', 'supplier.products',
            'supplier.products.type', 'supplier.expenseTemplates', 'vendorTrucking', 'warehouse', 'expenses')->find($poId);
    }

    /**
     * Get purchase order to be copied.
     *
     * @param string $poCode code of purchase order to be copied.
     * @return PurchaseOrder
     */
    public function getPOForCopy($poCode)
    {
        return PurchaseOrder::with('items.product.productUnits.unit', 'supplier.profiles.phoneNumbers.provider',
            'supplier.bankAccounts.bank', 'supplier.products.productUnits.unit', 'supplier.products.type',
            'supplier.expenseTemplates', 'vendorTrucking', 'warehouse')->where('code', '=', $poCode)->first();
    }
}